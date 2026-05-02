<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

class DbQuery
{
    public function __construct(
        public string $type,       // 'eloquent' | 'raw'
        public string $model,      // FQCN for eloquent, '' for raw
        public string $table,      // derived table name
        public string $operation,  // 'select' | 'insert' | 'update' | 'delete' | 'query'
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'model' => $this->model,
            'table' => $this->table,
            'operation' => $this->operation,
        ];
    }
}

/**
 * Traces database operations reachable from each controller action.
 *
 * Walks the already-computed call chain (Eloquent model hops) and also
 * scans method ASTs for DB facade calls.  Results are keyed by
 * "ControllerFqcn::method" and stored on the graph's action nodes.
 */
class QueryTracer
{
    private PhpFileParser $parser;

    private ProjectStructure $projectStructure;

    /** @var array<string, array|null> file path → parse result cache */
    private array $parseCache = [];

    /** @var string[] */
    private array $classSearchBases = [];

    /** @var array<string, string> */
    private array $resolvedPsr4Map = [];

    public function __construct()
    {
        $this->parser = new PhpFileParser;
        $this->projectStructure = new ProjectStructure;
    }

    /**
     * Build a map of DB queries reachable from each controller action.
     *
     * @param  CallChainEdge[]  $callChain
     * @param  array<string, ControllerDefinition>  $controllers
     * @param  array<string, string>  $psr4Map
     * @return array<string, DbQuery[]> "Fqcn::method" => queries
     */
    public function buildQueryMap(
        array $callChain,
        array $controllers,
        array $psr4Map,
        string $projectRoot,
    ): array {
        $this->classSearchBases = $this->projectStructure->discoverClassSearchBases($projectRoot);
        $this->resolvedPsr4Map = $psr4Map + $this->projectStructure->buildPsr4Map($projectRoot);

        // Build outgoing adjacency from the call chain
        $outgoing = []; // "fqcn::method" => CallChainEdge[]
        foreach ($callChain as $edge) {
            $outgoing[$edge->callerFqcn.'::'.$edge->callerMethod][] = $edge;
        }

        $queryMap = [];

        foreach ($controllers as $fqcn => $def) {
            foreach ($def->methods as $methodDef) {
                if ($methodDef->ast === null) {
                    continue;
                }

                $actionKey = $fqcn.'::'.$methodDef->name;

                // BFS from this action through the call graph
                $visited = [];
                $queue = [$actionKey];
                $queries = [];

                while (! empty($queue)) {
                    $key = array_shift($queue);
                    if (isset($visited[$key])) {
                        continue;
                    }
                    $visited[$key] = true;

                    foreach ($outgoing[$key] ?? [] as $edge) {
                        $calleeKey = $edge->calleeFqcn.'::'.$edge->calleeMethod;

                        if ($edge->type === 'model') {
                            $queries[] = new DbQuery(
                                type: 'eloquent',
                                model: $edge->calleeFqcn,
                                table: $this->deriveTableName($edge->calleeFqcn),
                                operation: $edge->calleeMethod,
                            );
                        } elseif (in_array($edge->type, ['service', 'repository'], true)) {
                            $queue[] = $calleeKey;
                        }
                    }
                }

                // Scan the action method itself for DB:: facade calls
                $queries = array_merge(
                    $queries,
                    $this->scanMethodForDbCalls($methodDef->ast, $def->useMap),
                );

                // Scan every service/repository visited transitively
                foreach (array_keys($visited) as $visitedKey) {
                    if ($visitedKey === $actionKey) {
                        continue;
                    }
                    [$visitedFqcn, $visitedMethod] = explode('::', $visitedKey, 2);
                    $queries = array_merge(
                        $queries,
                        $this->scanClassMethodForDbCalls($visitedFqcn, $visitedMethod),
                    );
                }

                if (! empty($queries)) {
                    $queryMap[$actionKey] = $this->deduplicate($queries);
                }
            }
        }

        return $queryMap;
    }

    // ── AST scanning ──────────────────────────────────────────────────────────

    /**
     * Scan a parsed ClassMethod node for DB:: facade calls.
     *
     * @return DbQuery[]
     */
    private function scanMethodForDbCalls(Node\Stmt\ClassMethod $ast, array $useMap): array
    {
        $traverser = new NodeTraverser;
        $visitor = new DbFacadeVisitor($useMap);
        $traverser->addVisitor($visitor);
        $traverser->traverse([$ast]);

        return $visitor->queries;
    }

    /**
     * Resolve a class method by FQCN + method name and scan it for DB:: calls.
     *
     * @return DbQuery[]
     */
    private function scanClassMethodForDbCalls(
        string $fqcn,
        string $method,
    ): array {
        $file = $this->resolveFile($fqcn);
        if ($file === null || ! file_exists($file)) {
            return [];
        }

        if (! isset($this->parseCache[$file])) {
            $this->parseCache[$file] = $this->parser->parse($file);
        }
        $parsed = $this->parseCache[$file];
        if (! $parsed || ! $parsed['ast']) {
            return [];
        }

        $ast = $this->findMethodAst($parsed['ast'], $method);
        if ($ast === null) {
            return [];
        }

        return $this->scanMethodForDbCalls($ast, $parsed['useMap'] ?? []);
    }

    private function findMethodAst(array $ast, string $methodName): ?Node\Stmt\ClassMethod
    {
        $traverser = new NodeTraverser;
        $finder = new class($methodName) extends NodeVisitorAbstract
        {
            public ?Node\Stmt\ClassMethod $found = null;

            public function __construct(private string $target) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\ClassMethod && $node->name->toString() === $this->target) {
                    $this->found = $node;

                    return NodeVisitor::STOP_TRAVERSAL;
                }

                return null;
            }
        };
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        return $finder->found;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveFile(string $fqcn): ?string
    {
        foreach ($this->resolvedPsr4Map as $namespace => $basePath) {
            if (str_starts_with($fqcn, $namespace.'\\')) {
                $relative = substr($fqcn, strlen($namespace) + 1);
                $path = $basePath.'/'.str_replace('\\', '/', $relative).'.php';
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        // Fallback: discovered class roots
        $relative = str_replace('\\', '/', $fqcn).'.php';
        foreach ($this->classSearchBases as $base) {
            $path = $base.'/'.$relative;
            if (file_exists($path)) {
                return $path;
            }
        }

        return $this->searchByClassName($fqcn);
    }

    private function searchByClassName(string $fqcn): ?string
    {
        $shortName = str_contains($fqcn, '\\')
            ? substr($fqcn, strrpos($fqcn, '\\') + 1)
            : $fqcn;
        $filename = $shortName.'.php';

        foreach ($this->classSearchBases as $base) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getFilename() === $filename) {
                    return $file->getPathname();
                }
            }
        }

        return null;
    }

    /**
     * Derive a database table name from an Eloquent model FQCN.
     * App\Models\OrderItem  →  order_items
     */
    private function deriveTableName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        $short = end($parts);

        // PascalCase → snake_case
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short));

        // Simple English pluralisation
        if (preg_match('/[^aeiou]y$/', $snake)) {
            return substr($snake, 0, -1).'ies'; // category → categories
        }
        if (preg_match('/(ss|x|ch|sh)$/', $snake)) {
            return $snake.'es'; // class → classes
        }

        return $snake.'s';
    }

    /** @param DbQuery[] $queries */
    private function deduplicate(array $queries): array
    {
        $seen = [];
        $unique = [];
        foreach ($queries as $q) {
            $key = $q->type.'|'.$q->model.'|'.$q->table.'|'.$q->operation;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $q;
            }
        }

        return $unique;
    }
}

// ── DB Facade visitor ─────────────────────────────────────────────────────────

/**
 * Visits an AST and collects all DB:: static-call queries.
 */
class DbFacadeVisitor extends NodeVisitorAbstract
{
    /** @var DbQuery[] */
    public array $queries = [];

    private array $useMap;

    private const DB_OPERATION_MAP = [
        'select' => 'select',
        'selectOne' => 'select',
        'cursor' => 'select',
        'insert' => 'insert',
        'update' => 'update',
        'delete' => 'delete',
        'statement' => 'statement',
        'unprepared' => 'statement',
        'table' => 'query',
        'raw' => 'query',
    ];

    public function __construct(array $useMap)
    {
        $this->useMap = $useMap;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Expr\StaticCall) {
            $this->handleStaticCall($node);
        }

        return null;
    }

    private function handleStaticCall(Node\Expr\StaticCall $node): void
    {
        if (! $node->class instanceof Node\Name) {
            return;
        }

        $class = $node->class->toString();
        $fqcn = $this->useMap[$class] ?? $class;

        if ($class !== 'DB' && $fqcn !== 'Illuminate\\Support\\Facades\\DB') {
            return;
        }

        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
        if ($method === null || ! isset(self::DB_OPERATION_MAP[$method])) {
            return;
        }

        $operation = self::DB_OPERATION_MAP[$method];
        $table = '';

        // DB::table('tablename') — extract from first arg
        if ($method === 'table' && ! empty($node->args)) {
            $arg = $node->args[0];
            $val = $arg instanceof Node\Arg ? $arg->value : $arg;
            if ($val instanceof Node\Scalar\String_) {
                $table = $val->value;
            }
        }

        // DB::select / insert / update / delete with raw SQL string
        if (in_array($method, ['select', 'selectOne', 'insert', 'update', 'delete', 'statement', 'unprepared'], true)
            && ! empty($node->args)
        ) {
            $arg = $node->args[0];
            $val = $arg instanceof Node\Arg ? $arg->value : $arg;
            if ($val instanceof Node\Scalar\String_) {
                $table = $this->extractTableFromSql($val->value);
            }
        }

        $this->queries[] = new DbQuery(
            type: 'raw',
            model: '',
            table: $table,
            operation: $operation,
        );
    }

    private function extractTableFromSql(string $sql): string
    {
        if (preg_match('/\b(?:FROM|INTO|UPDATE|TABLE)\s+`?(\w+)`?/i', $sql, $m)) {
            return $m[1];
        }

        return '';
    }
}
