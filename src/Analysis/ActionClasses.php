<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Recognises single-purpose "action" classes — one class, one public entry method, invoked
 * to perform one deliberate unit of work.
 *
 * Why a directory and not a name: the pattern has no naming convention worth matching. The
 * classes are called `CreateOrder`, `PublishPost`, `ChargeCustomer` — verbs, with nothing in
 * the identifier to key on, and a `*Action` suffix test would both miss all of those and claim
 * every Filament table action in a Filament application. What the pattern does have is a
 * placement convention: the classes are gathered under an `Actions/` directory, which is what
 * every generator (`lorisleiva/laravel-actions`, `spatie/laravel-queueable-action`, and the
 * hand-rolled variant) writes, and what makes the group legible in the first place. So the
 * directory is the signal, exactly as it already is for listeners and observers.
 *
 * Classification is deliberately conservative: this only ever fires against a class the rest
 * of the pipeline already gave up on and called a plain `service`. A job, listener, model,
 * event, facade or Form Request that happens to sit under `Actions/` keeps the kind that
 * recognised it, because that kind says more than "action class" does.
 */
final class ActionClasses
{
    /**
     * Where the pattern lives in a default Laravel skeleton. An application that keeps its
     * code elsewhere points `laravel-brain.actions.paths` at its own tree; glob patterns are
     * expanded, so a modular monolith names its packages with a wildcard segment.
     *
     * @var string[]
     */
    public const DEFAULT_PATHS = ['app/Actions'];

    /**
     * The three spellings the pattern uses for its entry point, in the order they are
     * considered. `__invoke` comes first because a class that has it is callable, and the
     * other two are then almost always its delegates.
     *
     * @var string[]
     */
    public const ENTRY_METHODS = ['__invoke', 'handle', 'execute'];

    private readonly PhpFileParser $parser;

    /**
     * Resolved action directories, relative to the project root. Resolving means a glob
     * expansion when a pattern has a wildcard, so it is done once rather than per lookup —
     * {@see SourceDirectories::resolve()} memoizes across instances, this holds the result
     * for the common case of one instance answering thousands of questions.
     *
     * @var string[]|null
     */
    private ?array $directories = null;

    /** @var array<string, bool> absolute file path => sits under an action directory */
    private array $containsMemo = [];

    /** @var array<string, string|null> absolute file path => sole entry method, or null */
    private array $entryMethodMemo = [];

    /**
     * @param  string[]  $paths  action roots, relative to the project root; globs are expanded
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $paths = self::DEFAULT_PATHS,
        ?PhpFileParser $parser = null,
    ) {
        $this->parser = $parser ?? new PhpFileParser;
    }

    /**
     * Whether the class in this file is an action class.
     *
     * Containment is anchored at the project root rather than tested as a substring, for the
     * reason {@see SourceDirectories::contains()} gives: a project that itself lives under a
     * directory called `Actions` would otherwise have every one of its files claimed.
     */
    public function isActionClass(string $file): bool
    {
        if ($file === '' || $this->projectRoot === '') {
            return false;
        }

        return $this->containsMemo[$file] ??= SourceDirectories::contains(
            $this->projectRoot,
            $this->directories(),
            $file,
        );
    }

    /**
     * The class's single public entry method, or null when it does not have exactly one.
     *
     * "Exactly one" is the point of the answer. A class under `Actions/` declaring both
     * `handle()` and `execute()` publicly is not the single-entry-point unit this describes,
     * and naming one of them as *the* entry method would be a guess presented as a fact — so
     * nothing is claimed. The class stays an action class by placement; the graph just does
     * not tell the reader where to start reading it.
     *
     * Non-public declarations do not count: a protected `handle()` is an implementation
     * detail of whatever calls it, not a way in.
     */
    public function entryMethod(string $file): ?string
    {
        if (! $this->isActionClass($file)) {
            return null;
        }

        if (array_key_exists($file, $this->entryMethodMemo)) {
            return $this->entryMethodMemo[$file];
        }

        $declared = $this->declaredEntryMethods($file);

        return $this->entryMethodMemo[$file] = count($declared) === 1 ? $declared[0] : null;
    }

    /**
     * @return string[] relative to the project root
     */
    private function directories(): array
    {
        return $this->directories ??= SourceDirectories::resolve($this->projectRoot, $this->paths);
    }

    /**
     * Public methods of the file's class whose name is one of {@see ENTRY_METHODS}, returned
     * in that constant's order regardless of declaration order, so the answer does not depend
     * on how the file happens to be written.
     *
     * @return list<string>
     */
    private function declaredEntryMethods(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return [];
        }

        $visitor = new class extends NodeVisitorAbstract
        {
            /** @var array<string, true> lower-cased public method names of the first class */
            public array $publicMethods = [];

            public function enterNode(Node $node): ?int
            {
                if (! $node instanceof Node\Stmt\Class_) {
                    return null;
                }

                foreach ($node->stmts as $stmt) {
                    if (! $stmt instanceof Node\Stmt\ClassMethod || ! $stmt->isPublic()) {
                        continue;
                    }
                    $this->publicMethods[strtolower($stmt->name->toString())] = true;
                }

                // The first class declaration in the file is the one the FQCN names; a second
                // one would be a helper sharing the file, and its methods are not this
                // class's entry points.
                return NodeVisitor::STOP_TRAVERSAL;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        $declared = $visitor->publicMethods;

        return array_values(array_filter(
            self::ENTRY_METHODS,
            static fn (string $candidate): bool => isset($declared[strtolower($candidate)]),
        ));
    }
}
