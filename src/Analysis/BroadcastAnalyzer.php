<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * One broadcast channel an event goes out on.
 */
class BroadcastChannel
{
    public function __construct(
        /** The channel name as written, with each unreadable segment rendered as a placeholder. */
        public string $name,
        /** public | private | presence — the three Laravel channel classes. */
        public string $kind,
        /** True when nothing in the name was a literal, so only the kind is known. */
        public bool $computed = false,
    ) {}
}

/**
 * What an event promises when it broadcasts.
 */
class BroadcastDefinition
{
    /** @param list<BroadcastChannel> $channels */
    public function __construct(
        public string $fqcn,
        public array $channels,
        /** ShouldBroadcast goes through the queue; ShouldBroadcastNow does not. */
        public bool $queued = true,
        /** The name subscribers listen for, when `broadcastAs()` renames it. */
        public ?string $alias = null,
        /** `broadcastWith()` is declared, so the payload is not the event's public properties. */
        public bool $customPayload = false,
        /** `broadcastWhen()` is declared, so it does not always go out. */
        public bool $conditional = false,
        /** A literal queue name from `broadcastQueue()`. */
        public ?string $queue = null,
    ) {}
}

/**
 * Reads which events broadcast, and onto which channels.
 *
 * The graph already held both ends of this and nothing in between: `ChannelAnalyzer` reads the
 * channels an application authorises in `routes/channels.php`, and events are nodes in their own
 * right — but nothing said which event reaches which channel, which is the only question anyone
 * asks about broadcasting. This pass is the edge between them.
 *
 * It is deliberately **declaration-based**: an event advertises itself by implementing
 * `ShouldBroadcast`, and `broadcastOn()` is read from the class body. Nothing here depends on the
 * call-chain tracer reaching the event, which is what bounds every "attach facts to a node the
 * tracer found" pass — measured repeatedly on a 60-module application at a fraction of what
 * exists. An event nobody dispatches from a traced path still broadcasts, and still shows.
 *
 * What it does not do: resolve a channel name built at runtime. `new PrivateChannel($this->room)`
 * names a channel this cannot know, and it is reported as computed rather than guessed — the same
 * choice the outgoing-HTTP and cache passes make about half-readable values.
 */
class BroadcastAnalyzer
{
    /** The three channel classes Laravel ships, mapped to the kind the graph shows. */
    private const CHANNEL_KINDS = [
        'Channel' => 'public',
        'PrivateChannel' => 'private',
        'PresenceChannel' => 'presence',
        'Illuminate\\Broadcasting\\Channel' => 'public',
        'Illuminate\\Broadcasting\\PrivateChannel' => 'private',
        'Illuminate\\Broadcasting\\PresenceChannel' => 'presence',
    ];

    private const BROADCAST_INTERFACES = [
        'ShouldBroadcast' => true,
        'ShouldBroadcastNow' => true,
        'Illuminate\\Contracts\\Broadcasting\\ShouldBroadcast' => true,
        'Illuminate\\Contracts\\Broadcasting\\ShouldBroadcastNow' => true,
    ];

    private PhpFileParser $parser;

    private NodeFinder $finder;

    /** @var string[] directories (relative to the project root, globs expanded) holding events */
    private array $paths;

    /** @param string[] $paths */
    public function __construct(array $paths = ['app/Events'])
    {
        $this->parser = new PhpFileParser;
        $this->finder = new NodeFinder;
        $this->paths = $paths !== [] ? $paths : ['app/Events'];
    }

    /**
     * @return array<string, BroadcastDefinition> event FQCN => what it broadcasts
     */
    public function analyze(string $projectRoot): array
    {
        $found = [];

        foreach (SourceDirectories::phpFiles($projectRoot, SourceDirectories::resolve($projectRoot, $this->paths)) as $file) {
            $definition = $this->fromFile($file);

            if ($definition !== null) {
                $found[$definition->fqcn] = $definition;
            }
        }

        ksort($found);

        return $found;
    }

    private function fromFile(string $file): ?BroadcastDefinition
    {
        $parsed = $this->parser->parse($file);

        if ($parsed['ast'] === null) {
            return null;
        }

        $class = $this->finder->findFirstInstanceOf($parsed['ast'], Node\Stmt\Class_::class);

        if (! $class instanceof Node\Stmt\Class_ || $class->name === null) {
            return null;
        }

        $useMap = $parsed['useMap'] ?? [];
        $queued = null;

        foreach ($class->implements as $interface) {
            $name = $interface->toString();
            $resolved = $useMap[$name] ?? $name;

            if (! isset(self::BROADCAST_INTERFACES[$name]) && ! isset(self::BROADCAST_INTERFACES[$resolved])) {
                continue;
            }

            // Now beats queued: an event declaring both is sent synchronously.
            $queued = $queued === false
                ? false
                : ! str_contains($resolved, 'ShouldBroadcastNow');
        }

        if ($queued === null) {
            return null;
        }

        $namespaceNode = $this->finder->findFirstInstanceOf($parsed['ast'], Node\Stmt\Namespace_::class);
        $namespace = $namespaceNode instanceof Node\Stmt\Namespace_ && $namespaceNode->name !== null
            ? $namespaceNode->name->toString()
            : '';
        $fqcn = ($namespace !== '' ? $namespace.'\\' : '').$class->name->toString();

        return new BroadcastDefinition(
            fqcn: $fqcn,
            channels: $this->channelsIn($class, $useMap),
            queued: $queued,
            alias: $this->literalReturnOf($class, 'broadcastAs'),
            customPayload: $this->declares($class, 'broadcastWith'),
            conditional: $this->declares($class, 'broadcastWhen'),
            queue: $this->literalReturnOf($class, 'broadcastQueue'),
        );
    }

    /**
     * Every channel `broadcastOn()` names.
     *
     * The method may return one channel or an array of them, and either may be built from a
     * literal, a concatenation or a variable — so the constructions are collected wherever they
     * appear in the method rather than by matching a return shape. An event with no readable
     * channel construction returns none, which is a true statement about what can be read.
     *
     * @param  array<string, string>  $useMap
     * @return list<BroadcastChannel>
     */
    private function channelsIn(Node\Stmt\Class_ $class, array $useMap): array
    {
        $method = $this->method($class, 'broadcastOn');

        if ($method === null) {
            return [];
        }

        $channels = [];

        foreach ($this->finder->findInstanceOf([$method], Node\Expr\New_::class) as $new) {
            if (! $new->class instanceof Node\Name) {
                continue;
            }

            $name = $new->class->toString();
            $kind = self::CHANNEL_KINDS[$name] ?? self::CHANNEL_KINDS[$useMap[$name] ?? ''] ?? null;

            if ($kind === null) {
                continue;
            }

            $argument = $new->args[0] ?? null;
            [$rendered, $computed] = $argument instanceof Node\Arg
                ? $this->renderChannelName($argument->value)
                : ['', true];

            $channels[] = new BroadcastChannel($rendered, $kind, $computed);
        }

        return $channels;
    }

    /**
     * The channel name, with every part this cannot read rendered as a placeholder.
     *
     * `'orders.'.$this->order->id` and `"orders.{$this->order->id}"` both come out `orders.{id}`,
     * so the two spellings of one channel do not read as two channels. The placeholder takes the
     * expression's last identifier because that is what the application's own channel routes are
     * named with — `Broadcast::channel('orders.{orderId}', …)` — which is what makes the two
     * comparable at all.
     *
     * A name counts as computed when every dot-separated segment is a placeholder — not when no
     * literal text survived. `$this->scope.'.'.$this->ref` has a literal in it, the separator,
     * and that separator is the application's own and says nothing about which channel is meant.
     * Segments are the right unit because segments are what the name is matched on.
     *
     * @return array{0: string, 1: bool} the name, and whether any segment identifies it
     */
    private function renderChannelName(Node\Expr $expr): array
    {
        $rendered = $this->renderPart($expr);

        $identifying = array_filter(
            explode('.', $rendered),
            static fn (string $segment): bool => ! (str_starts_with($segment, '{') && str_ends_with($segment, '}')),
        );

        return [$rendered, $identifying === []];
    }

    private function renderPart(Node\Expr $expr): string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            return $this->renderPart($expr->left).$this->renderPart($expr->right);
        }

        if ($expr instanceof Node\Scalar\InterpolatedString) {
            $out = '';

            foreach ($expr->parts as $part) {
                $out .= $part instanceof Node\InterpolatedStringPart
                    ? $part->value
                    : $this->renderPart($part);
            }

            return $out;
        }

        if ($expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name) {
            return $expr->class->toString();
        }

        return '{'.$this->placeholderFor($expr).'}';
    }

    /** The last identifier in an expression, which is the closest thing to a name it has. */
    private function placeholderFor(Node\Expr $expr): string
    {
        if ($expr instanceof Node\Expr\PropertyFetch && $expr->name instanceof Node\Identifier) {
            return $expr->name->toString();
        }

        if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            return $expr->name->toString();
        }

        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return $expr->name;
        }

        return '…';
    }

    private function method(Node\Stmt\Class_ $class, string $name): ?Node\Stmt\ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if (strcasecmp($method->name->toString(), $name) === 0) {
                return $method;
            }
        }

        return null;
    }

    private function declares(Node\Stmt\Class_ $class, string $name): bool
    {
        return $this->method($class, $name) !== null;
    }

    /** The literal string a method returns, or null when it returns something this cannot read. */
    private function literalReturnOf(Node\Stmt\Class_ $class, string $name): ?string
    {
        $method = $this->method($class, $name);

        if ($method === null) {
            return null;
        }

        foreach ($this->finder->findInstanceOf([$method], Node\Stmt\Return_::class) as $return) {
            if ($return->expr instanceof Node\Scalar\String_) {
                return $return->expr->value;
            }
        }

        return null;
    }
}
