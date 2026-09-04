<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis\Reachability;

use LaraMint\LaravelBrain\Analysis\SourceDirectories;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Every place a class is named as a *string* rather than called: `Foo::class` and a quoted
 * `'App\Jobs\Foo'`.
 *
 * This exists to keep the reachability report honest. A class registered in an array — a
 * config entry, a `$middleware` list, a cast map, a Filament schema, a provider's
 * `$policies` — is very much alive, and the call tracer cannot see any of it, because there
 * is no call to follow: the framework resolves the name later, at a point no static chain
 * reaches. Reporting such a class as unreached without saying *how* it is referenced is the
 * difference between a useful inventory and a list nobody trusts.
 *
 * A reference in the class's own declaring file does not count — `self::class` aside, a
 * class naming itself says nothing about who else uses it.
 */
final class ClassStringIndex
{
    /**
     * A quoted string worth resolving: at least one namespace separator, each segment a PHP
     * identifier. Narrow on purpose — a looser pattern matches Windows paths and regexes, and
     * a false "it is referenced somewhere" hint is worse than no hint at all.
     *
     * Public because the scanning visitor below is an anonymous class, which does not inherit
     * the enclosing class's private scope.
     */
    public const FQCN_PATTERN = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)+$/';

    /**
     * @param  array<string, list<string>>  $references  FQCN => files naming it
     */
    private function __construct(private array $references) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  string[]  $patterns  directories or glob patterns, relative to the project root
     */
    public static function scan(string $projectRoot, array $patterns): self
    {
        $directories = SourceDirectories::resolve($projectRoot, $patterns);
        $parser = new PhpFileParser;
        $references = [];

        foreach (SourceDirectories::phpFiles($projectRoot, $directories) as $file) {
            foreach (self::namesIn($parser, $file) as $fqcn) {
                $references[$fqcn][$file] = true;
            }
        }

        return new self(array_map(
            static fn (array $files): array => array_keys($files),
            $references,
        ));
    }

    /**
     * Files that name the class, other than the one given as its declaration site.
     *
     * @return list<string>
     */
    public function referencesTo(string $fqcn, string $exceptFile = ''): array
    {
        $files = $this->references[$fqcn] ?? [];

        if ($exceptFile === '') {
            return $files;
        }

        return array_values(array_filter($files, static fn (string $f): bool => $f !== $exceptFile));
    }

    public function hasReferenceTo(string $fqcn, string $exceptFile = ''): bool
    {
        return $this->referencesTo($fqcn, $exceptFile) !== [];
    }

    /**
     * @return list<string>
     */
    private static function namesIn(PhpFileParser $parser, string $file): array
    {
        $parsed = $parser->parse($file);
        if ($parsed['ast'] === null) {
            return [];
        }

        $visitor = new class extends NodeVisitorAbstract
        {
            /** @var array<string, true> */
            public array $found = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Expr\ClassConstFetch
                    && $node->name instanceof Node\Identifier
                    && strtolower($node->name->toString()) === 'class') {
                    $resolved = PhpFileParser::resolvedName($node->class);
                    if ($resolved !== null) {
                        $this->found[$resolved] = true;
                    }

                    return null;
                }

                if ($node instanceof Node\Scalar\String_
                    && preg_match(ClassStringIndex::FQCN_PATTERN, $node->value) === 1) {
                    $this->found[ltrim($node->value, '\\')] = true;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return array_keys($visitor->found);
    }
}
