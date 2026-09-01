<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;

/**
 * Finds the methods a project uses to build HTTP requests, so that calls made through them are
 * recognised as outgoing calls.
 *
 * The shape this exists for is the house style of every application that wraps its integrations:
 *
 *     class AllegroHttpClient
 *     {
 *         public function api(): PendingRequest
 *         {
 *             return TransientFailureRetry::applyTo(Http::baseUrl($this->url()))->timeout(5);
 *         }
 *     }
 *
 *     $this->client->api()->get('/me');
 *
 * The verb is in one file and the `Http` facade is in another, so a scanner reading one method at
 * a time sees a chain rooted in `$this->client->api()` and reports nothing. Measured on a 60-module
 * application: 50 files make HTTP calls and the graph named **one**.
 *
 * What is matched is a **declaration**, never a guess: a method whose written return type is
 * `Illuminate\Http\Client\PendingRequest`. A method called `api()` that returns `array`, or one
 * with no return type at all, is not a builder here and never becomes one — a name is not evidence.
 *
 * ## The limitation, stated plainly
 *
 * The result is keyed on the **method name**, not on the class the call site's receiver resolves
 * to. `$this->client->api()` gives us the name `api` and nothing else: the type of `$this->client`
 * is declared in the *caller's* class, which a method AST does not carry, and following it would
 * mean cross-file type resolution that this extractor does not have.
 *
 * So the rule reads: *some class in this project declares `api(): PendingRequest`, therefore
 * `->api()->get(...)` is an outgoing call.* Two consequences, both real:
 *
 *  - A different class with a same-named method feeding a chain into a `get()` is reported too.
 *    The false positive needs a coincidence of name *and* of being used as a request, and it names
 *    a call that is at least shaped like one.
 *  - Where two declarations of the same name disagree about their base URL or timeout, neither is
 *    reported. Settings survive only where every declaration of that name agrees — an inherited
 *    host or timeout is worse than none, because it reads as a fact about this call site.
 */
final class PendingRequestAnalyzer
{
    private PhpFileParser $parser;

    /** @var string[] source directories, relative to the project root; glob patterns are expanded */
    private array $paths;

    /**
     * @param  string[]  $paths  source directories, relative to the project root
     */
    public function __construct(?PhpFileParser $parser = null, array $paths = SourceDirectories::DEFAULT_SOURCE_PATHS)
    {
        $this->parser = $parser ?? new PhpFileParser;
        $this->paths = $paths;
    }

    /**
     * Builder method names found in the project, each with the request settings that every
     * declaration of that name agrees on.
     *
     * @return array<string, array<string, mixed>> method name => settings
     */
    public function analyze(string $projectRoot): array
    {
        $projectRoot = rtrim($projectRoot, '/');
        $sourceDirs = SourceDirectories::resolve($projectRoot, $this->paths);
        if ($sourceDirs === []) {
            return [];
        }

        $candidates = [];
        foreach (SourceDirectories::phpFiles($projectRoot, $sourceDirs) as $file) {
            // A declaration has to write the type, so the token is in the file. Reading a file and
            // testing for a substring is a fraction of the cost of parsing one, and on any real
            // project this leaves a handful of files to parse — the same prefilter FacadeAnalyzer
            // uses for `Facade`.
            $code = @file_get_contents($file);
            if ($code !== false && str_contains($code, 'PendingRequest')) {
                $candidates[] = $file;
            }
        }

        // Two passes, because builders lean on each other: `api()` returns
        // `TransientFailureRetry::applyTo(Http::baseUrl(...))->timeout(5)`, and the retry policy
        // that wrapper applies is only readable once `applyTo` is itself known to be a builder.
        // The first pass learns the names; the second re-reads the same bodies knowing them, so a
        // builder standing on one other builder reports the settings of both. A builder standing
        // on two is recognised as a builder and reports what the second pass could see — the files
        // are already parsed by then, so the cost of the extra pass is the walk, not the parse.
        $registry = $this->collect($candidates, []);

        return $this->collect($candidates, $registry);
    }

    /**
     * @param  string[]  $candidates
     * @param  array<string, array<string, mixed>>  $known  builders already identified
     * @return array<string, array<string, mixed>>
     */
    private function collect(array $candidates, array $known): array
    {
        /** @var array<string, list<array<string, mixed>>> $declarations */
        $declarations = [];

        foreach ($candidates as $file) {
            foreach ($this->declarationsIn($file, $known) as $name => $settings) {
                $declarations[$name][] = $settings;
            }
        }

        return array_map($this->agreedSettings(...), $declarations);
    }

    /**
     * The builder methods one file declares, each with whatever its own body could be read to say.
     *
     * @param  array<string, array<string, mixed>>  $known  builders already identified
     * @return array<string, array<string, mixed>>
     */
    private function declarationsIn(string $file, array $known): array
    {
        $parsed = $this->parser->parse($file);
        $ast = $parsed['ast'] ?? null;
        if (! is_array($ast)) {
            return [];
        }

        $useMap = $parsed['useMap'] ?? [];
        $found = [];

        foreach ($this->classMethods($ast) as $method) {
            if (! HttpCallExtractor::namesPendingRequest($method->returnType, $useMap)) {
                continue;
            }

            $extractor = new HttpCallExtractor;
            $extractor->setPendingRequestBuilders($known);
            $found[$method->name->toString()] = $extractor->builderSettings($method, $useMap) ?? [];
        }

        return $found;
    }

    /**
     * Every method declared by a class, interface, trait or enum in a parsed file.
     *
     * An interface counts: `public function api(): PendingRequest;` on a contract is a declaration
     * that the call site is entitled to rely on, and it is often the only place the type is
     * written down.
     *
     * @param  Node[]  $nodes
     * @return iterable<Node\Stmt\ClassMethod>
     */
    private function classMethods(array $nodes): iterable
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                yield from $this->classMethods($node->stmts);

                continue;
            }

            if ($node instanceof Node\Stmt\ClassLike) {
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\ClassMethod) {
                        yield $stmt;
                    }
                }
            }
        }
    }

    /**
     * The settings shared by every declaration of one name, dropping any the declarations disagree
     * about.
     *
     * Matching is by name, so a call site could mean any of them. A base URL or timeout borrowed
     * from the wrong one would be printed as this call's own, which is worse than printing nothing:
     * an absent timeout reads as "not declared", a wrong one reads as a fact.
     *
     * @param  list<array<string, mixed>>  $declarations
     * @return array<string, mixed>
     */
    private function agreedSettings(array $declarations): array
    {
        $agreed = array_shift($declarations) ?? [];

        foreach ($declarations as $other) {
            foreach ($agreed as $key => $value) {
                if (! array_key_exists($key, $other) || $other[$key] != $value) {
                    unset($agreed[$key]);
                }
            }
        }

        return $agreed;
    }
}
