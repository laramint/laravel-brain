<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use Illuminate\Support\Str;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class RouteDefinition
{
    public function __construct(
        public string $method,
        public string $uri,
        public string $controller,
        public string $action,
        public array $middlewares,
        public string $name,
        public string $file,
        public int $line,
        public string $tabGroup = 'default',
        /** @var Node\Expr\Closure|Node\Expr\ArrowFunction|null Inline closure AST for closure routes */
        public ?Node $closureNode = null,
        /** The URI portion that came from Route::prefix() stacking (e.g. "/admin/auth") */
        public string $uriPrefix = '',
    ) {}
}

class RouteAnalyzer
{
    private PhpFileParser $parser;

    /** @var string[] */
    private array $routePaths;

    /**
     * @param  string[]  $routePaths  Glob patterns relative to the project root.
     *                                Defaults to ['routes/*\/*.php'].
     */
    public function __construct(array $routePaths = ['routes/*/*.php'])
    {
        $this->parser = new PhpFileParser;
        $this->routePaths = $routePaths ?: ['routes/*/*.php'];
    }

    /**
     * @return RouteDefinition[]
     */
    public function analyze(string $projectRoot): array
    {
        $routes = [];
        $routeFiles = $this->findRouteFiles($projectRoot);

        foreach ($routeFiles as $file) {
            $parsed = $this->parser->parse($file);
            if ($parsed['ast'] === null) {
                continue;
            }

            $routes = array_merge($routes, $this->extractRoutes($parsed['ast'], $parsed['useMap'], $file));
        }

        return $routes;
    }

    private function findRouteFiles(string $projectRoot): array
    {
        $root = rtrim($projectRoot, '/');
        $files = [];

        foreach ($this->routePaths as $pattern) {
            $baseDir = $this->resolveBaseDir($root, $pattern);

            if (! is_dir($baseDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        return array_unique($files);
    }

    /**
     * Extracts the fixed directory prefix from a glob pattern.
     *
     * For 'routes/*\/*.php'  → '{root}/routes'
     * For '*\/*\/*.php'      → '{root}'
     * For 'app/routes/*.php' → '{root}/app/routes'
     */
    private function resolveBaseDir(string $root, string $pattern): string
    {
        $segments = explode('/', ltrim($pattern, '/'));
        $fixed = [];

        foreach ($segments as $segment) {
            if (str_contains($segment, '*') || str_contains($segment, '?') || str_contains($segment, '[')) {
                break;
            }
            $fixed[] = $segment;
        }

        // Drop trailing filename segment (e.g. '*.php') if all segments were literal
        if (! empty($fixed) && str_ends_with(end($fixed), '.php')) {
            array_pop($fixed);
        }

        $subPath = implode('/', $fixed);

        return $subPath !== '' ? $root.'/'.$subPath : $root;
    }

    /**
     * Path for one expanded {@see ResourceRegistrar} action.
     *
     * @internal
     */
    public function resourceUriForAction(string $prefixedBaseUri, string $wildcard, string $actionMethod): string
    {
        $base = rtrim($prefixedBaseUri, '/');
        $param = '{'.$wildcard.'}';

        return match ($actionMethod) {
            'index' => $base,
            'create' => $base.'/create',
            'store' => $base,
            'show', 'update', 'destroy' => $base.'/'.$param,
            'edit' => $base.'/'.$param.'/edit',
            default => $base,
        };
    }

    /**
     * Mirrors {@see ResourceRegistrar::getResourceWildcard()}.
     *
     * @internal
     */
    public function resourceRouteWildcard(string $value): string
    {
        if (str_contains($value, '.')) {
            $segments = explode('.', $value);
            $value = end($segments) ?: $value;
        }
        if (str_contains($value, '/')) {
            $segments = explode('/', $value);
            $value = end($segments) ?: $value;
        }
        $value = str_replace(['{', '}'], '', $value);
        if ($value === '') {
            return 'id';
        }
        if (str_contains($value, '-')) {
            $value = Str::camel($value);
        }

        return Str::camel(Str::singular($value));
    }

    /**
     * @param  Node\Stmt[]  $ast
     * @param  array<string, string>  $useMap
     * @return RouteDefinition[]
     */
    private function extractRoutes(array $ast, array $useMap, string $file): array
    {
        $routes = [];
        $traverser = new NodeTraverser;

        $visitor = new class($useMap, $file, $this) extends NodeVisitorAbstract
        {
            public array $routes = [];

            private array $prefixStack = [];

            private array $middlewareStack = [];

            private array $namespaceStack = [];

            private array $useMap;

            private string $file;

            private RouteAnalyzer $routeAnalyzer;

            private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any'];

            /**
             * Methods that Laravel allows chaining AFTER a route definition:
             * Route::get(...)->middleware(...)->name(...)->where(...)
             * When the analyzer encounters one of these as the outermost call it must
             * walk down to find the HTTP method and collect middleware/name from the chain.
             */
            private const POST_ROUTE_CHAIN_METHODS = ['middleware', 'withoutMiddleware', 'name', 'where', 'defaults', 'scopeBindings', 'withTrashed', 'missing', 'can'];

            public function __construct(array $useMap, string $file, RouteAnalyzer $routeAnalyzer)
            {
                $this->useMap = $useMap;
                $this->file = $file;
                $this->routeAnalyzer = $routeAnalyzer;
            }

            public function enterNode(Node $node): ?int
            {
                // StaticCall: Route::get(), Route::group(), Route::resource()
                if ($node instanceof Node\Expr\StaticCall) {
                    $class = $this->resolveClass($node->class);
                    if ($class !== 'Route') {
                        return null;
                    }

                    $methodName = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
                    if ($methodName === null) {
                        return null;
                    }

                    if (in_array($methodName, self::HTTP_METHODS, true)) {
                        $this->handleHttpRoute($node, $methodName);
                    } elseif ($methodName === 'group') {
                        $this->enterGroupFromStaticCall($node);
                    } elseif (in_array($methodName, ['resource', 'apiResource'], true)) {
                        $this->handleResource($node, $methodName);
                    } elseif ($methodName === 'livewire') {
                        $this->handleLivewireRoute($node);
                    }

                    return null;
                }

                // MethodCall: Route::middleware([...])->group(), Route::prefix('x')->group(), Route::namespace('x')->group()
                // OR: Route::middleware([...])->get('/test', ...)
                // OR: Route::get('/test', ...)->middleware('ability:...') — post-route chain
                if ($node instanceof Node\Expr\MethodCall) {
                    $methodName = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
                    if ($methodName === 'group') {
                        $this->enterGroupFromMethodChain($node);
                    } elseif (in_array($methodName, self::HTTP_METHODS, true)) {
                        $this->handleHttpRoute($node, $methodName);
                    } elseif (in_array($methodName, ['resource', 'apiResource'], true)) {
                        $this->handleResource($node, $methodName);
                    } elseif ($methodName === 'livewire') {
                        $this->handleLivewireRoute($node);
                    } elseif (in_array($methodName, self::POST_ROUTE_CHAIN_METHODS, true)) {
                        // Pattern: Route::get(...)->middleware('ability:...') or ->name('...')->middleware('...')
                        // The HTTP route call is below in the AST; collect post-chain middleware and handle it.
                        if ($this->tryHandlePostChainedRoute($node)) {
                            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                        }
                    }
                }

                return null;
            }

            public function leaveNode(Node $node): ?int
            {
                $methodName = null;
                if ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) {
                    $methodName = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
                }

                if ($methodName === 'group') {
                    if (! empty($this->prefixStack)) {
                        array_pop($this->prefixStack);
                    }
                    if (! empty($this->middlewareStack)) {
                        array_pop($this->middlewareStack);
                    }
                    if (! empty($this->namespaceStack)) {
                        array_pop($this->namespaceStack);
                    }
                }

                return null;
            }

            /**
             * @param  string[]  $extraMiddlewares  Middleware collected from post-route chaining
             *                                      (e.g. Route::get(...)->middleware('ability:...'))
             */
            private function handleHttpRoute(Node\Expr\StaticCall|Node\Expr\MethodCall $node, string $method, array $extraMiddlewares = []): void
            {
                $uri = $this->extractString($node->args[0] ?? null);
                if ($uri === null) {
                    return;
                }

                [$controller, $actionMethod, $closureNode] = $this->extractAction($node->args[1] ?? null);

                // If it's a MethodCall, we might have prefixes/middlewares in the chain
                $chainPrefix = '';
                $chainMiddlewares = [];
                $chainNamespace = '';
                if ($node instanceof Node\Expr\MethodCall) {
                    $this->walkChain($node->var, $chainPrefix, $chainMiddlewares, $chainNamespace);
                }

                if ($controller !== 'Closure' && $controller !== '' && ! str_starts_with($controller, '\\')) {
                    $namespace = implode('\\', array_filter($this->namespaceStack));
                    if ($chainNamespace) {
                        $namespace = $namespace ? $namespace.'\\'.$chainNamespace : $chainNamespace;
                    }
                    if ($namespace) {
                        $controller = rtrim($namespace, '\\').'\\'.ltrim($controller, '\\');
                    }
                }

                $fullUri = implode('', $this->prefixStack).$chainPrefix.'/'.ltrim($uri, '/');
                $fullUri = '/'.ltrim($fullUri, '/');
                $fullUri = rtrim($fullUri, '/') ?: '/';

                // Record the prefix portion (from Route::prefix() stacking + any chain prefix).
                // This tells the UI exactly how deep the Route::prefix() nesting goes,
                // so it only creates sidebar folders for prefix-derived segments.
                $uriPrefix = '/'.ltrim(implode('', $this->prefixStack).$chainPrefix, '/');

                $middlewares = array_merge(
                    array_merge(...$this->middlewareStack ?: [[]]),
                    $chainMiddlewares,
                    $extraMiddlewares
                );

                $this->routes[] = new RouteDefinition(
                    method: strtoupper($method),
                    uri: $fullUri,
                    controller: $controller,
                    action: $actionMethod,
                    middlewares: array_unique($middlewares),
                    name: '',
                    file: $this->file,
                    line: $node->getStartLine(),
                    tabGroup: strtoupper($method).' '.$fullUri,
                    closureNode: $closureNode,
                    uriPrefix: $uriPrefix,
                );
            }

            /**
             * Handles Route::livewire('/uri', ComponentClass::class) — a Livewire v2 macro
             * that registers a GET route pointing to a Livewire component.
             *
             * @param  string[]  $extraMiddlewares  Middleware collected from post-route chaining
             *                                      (e.g. Route::livewire(...)->middleware('auth'))
             */
            private function handleLivewireRoute(Node\Expr\StaticCall|Node\Expr\MethodCall $node, array $extraMiddlewares = []): void
            {
                $uri = $this->extractString($node->args[0] ?? null);
                if ($uri === null) {
                    return;
                }

                $componentArg = $node->args[1] ?? null;
                $controller = '';
                if ($componentArg !== null) {
                    $val = $componentArg instanceof Node\Arg ? $componentArg->value : $componentArg;
                    $controller = $this->extractClassRef($val);
                    if ($controller === '') {
                        $controller = $this->extractString($componentArg) ?? '';
                    }
                }

                $chainPrefix = '';
                $chainMiddlewares = [];
                $chainNamespace = '';
                if ($node instanceof Node\Expr\MethodCall) {
                    $this->walkChain($node->var, $chainPrefix, $chainMiddlewares, $chainNamespace);
                }

                $fullUri = implode('', $this->prefixStack).$chainPrefix.'/'.ltrim($uri, '/');
                $fullUri = '/'.ltrim($fullUri, '/');
                $uriPrefix = '/'.ltrim(implode('', $this->prefixStack).$chainPrefix, '/');

                $middlewares = array_merge(
                    array_merge(...$this->middlewareStack ?: [[]]),
                    $chainMiddlewares,
                    $extraMiddlewares,
                );

                $this->routes[] = new RouteDefinition(
                    method: 'GET',
                    uri: $fullUri,
                    controller: $controller,
                    action: 'render',
                    middlewares: array_unique($middlewares),
                    name: '',
                    file: $this->file,
                    line: $node->getStartLine(),
                    tabGroup: 'GET '.$fullUri,
                    uriPrefix: $uriPrefix,
                );
            }

            /**
             * Handles routes written as Route::get(...)->middleware('ability:...') where
             * the HTTP method call is the var of one or more post-route chain calls.
             *
             * Walks down through the MethodCall chain collecting middleware, name, etc.
             * until it finds the base HTTP route call (StaticCall or MethodCall), then
             * registers the route with all collected post-chain middleware merged in.
             *
             * Returns true when a route was registered (caller should skip children).
             */
            private function tryHandlePostChainedRoute(Node\Expr\MethodCall $outerNode): bool
            {
                $postMiddlewares = [];
                $current = $outerNode;

                // Walk DOWN through post-route chain methods collecting middleware
                while ($current instanceof Node\Expr\MethodCall) {
                    $name = $current->name instanceof Node\Identifier ? $current->name->toString() : null;

                    if ($name === 'middleware' && ! empty($current->args)) {
                        $postMiddlewares = array_merge(
                            $postMiddlewares,
                            $this->extractMiddlewareList($current->args[0]->value)
                        );
                    }

                    // If we've reached the HTTP method call (e.g. ->get(), ->post()) stop here
                    if ($name !== null && in_array($name, self::HTTP_METHODS, true)) {
                        $this->handleHttpRoute($current, $name, $postMiddlewares);

                        return true;
                    }

                    $current = $current->var;
                }

                // Base of the chain is a StaticCall — Route::get('/brands', [...]) or Route::livewire(...)
                if ($current instanceof Node\Expr\StaticCall) {
                    $class = $this->resolveClass($current->class);
                    $name = $current->name instanceof Node\Identifier ? $current->name->toString() : null;

                    if ($class === 'Route' && $name !== null) {
                        if (in_array($name, self::HTTP_METHODS, true)) {
                            $this->handleHttpRoute($current, $name, $postMiddlewares);

                            return true;
                        }

                        if ($name === 'livewire') {
                            $this->handleLivewireRoute($current, $postMiddlewares);

                            return true;
                        }
                    }
                }

                return false;
            }

            private function handleResource(Node\Expr\StaticCall|Node\Expr\MethodCall $node, string $type): void
            {
                $uri = $this->extractString($node->args[0] ?? null);
                $controllerArg = $node->args[1] ?? null;
                if ($uri === null || $controllerArg === null) {
                    return;
                }

                $controllerFqcn = $this->extractClassRef($controllerArg->value);

                // Chain handling
                $chainPrefix = '';
                $chainMiddlewares = [];
                $chainNamespace = '';
                if ($node instanceof Node\Expr\MethodCall) {
                    $this->walkChain($node->var, $chainPrefix, $chainMiddlewares, $chainNamespace);
                }

                if ($controllerFqcn !== '' && ! str_starts_with($controllerFqcn, '\\')) {
                    $namespace = implode('\\', array_filter($this->namespaceStack));
                    if ($chainNamespace) {
                        $namespace = $namespace ? $namespace.'\\'.$chainNamespace : $chainNamespace;
                    }
                    if ($namespace) {
                        $controllerFqcn = rtrim($namespace, '\\').'\\'.ltrim($controllerFqcn, '\\');
                    }
                }

                $fullUri = implode('', $this->prefixStack).$chainPrefix.'/'.ltrim($uri, '/');
                $fullUri = '/'.ltrim($fullUri, '/');
                $uriPrefix = '/'.ltrim(implode('', $this->prefixStack).$chainPrefix, '/');
                $middlewares = array_merge(
                    array_merge(...$this->middlewareStack ?: [[]]),
                    $chainMiddlewares
                );

                $methods = $type === 'apiResource'
                    ? ['GET:index', 'POST:store', 'GET:show', 'PUT:update', 'PATCH:update', 'DELETE:destroy']
                    : ['GET:index', 'GET:create', 'POST:store', 'GET:show', 'GET:edit', 'PUT:update', 'PATCH:update', 'DELETE:destroy'];

                $wildcard = $this->routeAnalyzer->resourceRouteWildcard($uri);
                foreach ($methods as $spec) {
                    [$httpMethod, $actionMethod] = explode(':', $spec);
                    $routeUri = $this->routeAnalyzer->resourceUriForAction($fullUri, $wildcard, $actionMethod);
                    $this->routes[] = new RouteDefinition(
                        method: $httpMethod,
                        uri: $routeUri,
                        controller: $controllerFqcn,
                        action: $actionMethod,
                        middlewares: array_unique($middlewares),
                        name: '',
                        file: $this->file,
                        line: $node->getStartLine(),
                        tabGroup: $httpMethod.' '.$routeUri,
                        uriPrefix: $uriPrefix,
                    );
                }
            }

            private function enterGroupFromStaticCall(Node\Expr\StaticCall $node): void
            {
                $prefix = '';
                $middlewares = [];
                $namespace = '';

                foreach ($node->args as $arg) {
                    if (! $arg->value instanceof Node\Expr\Array_) {
                        continue;
                    }
                    foreach ($arg->value->items as $item) {
                        if ($item === null) {
                            continue;
                        }
                        $key = $item->key instanceof Node\Scalar\String_ ? $item->key->value : null;
                        if ($key === 'prefix') {
                            $prefix = $this->extractString($item) ?? '';
                        } elseif ($key === 'middleware') {
                            $middlewares = $this->extractMiddlewareList($item->value);
                        } elseif ($key === 'namespace') {
                            $namespace = $this->extractString($item) ?? '';
                        }
                    }
                }

                $this->prefixStack[] = $prefix ? '/'.ltrim($prefix, '/') : '';
                $this->middlewareStack[] = $middlewares;
                $this->namespaceStack[] = $namespace;
            }

            private function enterGroupFromMethodChain(Node\Expr\MethodCall $node): void
            {
                // Walk up the chain: ->group() called on ->middleware([...])->prefix(...) etc.
                $prefix = '';
                $middlewares = [];
                $namespace = '';
                $this->walkChain($node->var, $prefix, $middlewares, $namespace);

                $this->prefixStack[] = $prefix ? '/'.ltrim($prefix, '/') : '';
                $this->middlewareStack[] = $middlewares;
                $this->namespaceStack[] = $namespace;
            }

            private function walkChain(Node $node, string &$prefix, array &$middlewares, string &$namespace): void
            {
                if ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) {
                    $method = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

                    if ($method === 'middleware' && ! empty($node->args)) {
                        $middlewares = array_merge($middlewares, $this->extractMiddlewareList($node->args[0]->value));
                    } elseif ($method === 'prefix' && ! empty($node->args)) {
                        $prefix = $this->extractString($node->args[0]) ?? '';
                    } elseif ($method === 'namespace' && ! empty($node->args)) {
                        $namespace = $this->extractString($node->args[0]) ?? '';
                    }

                    // Walk the callee
                    $callee = $node instanceof Node\Expr\MethodCall ? $node->var : $node->class;
                    $this->walkChain($callee, $prefix, $middlewares, $namespace);
                }
            }

            /**
             * @return array{0: string, 1: string, 2: Node\Expr\Closure|Node\Expr\ArrowFunction|null}
             */
            private function extractAction(?Node $node): array
            {
                if ($node === null) {
                    return ['', '', null];
                }
                $value = $node instanceof Node\Arg ? $node->value : $node;

                // [Controller::class, 'method']
                if ($value instanceof Node\Expr\Array_ && count($value->items) >= 2) {
                    $classItem = $value->items[0];
                    $methodItem = $value->items[1];
                    if ($classItem && $methodItem) {
                        $controller = $this->extractClassRef($classItem->value);
                        $actionMethod = $this->extractString($methodItem) ?? '';

                        return [$controller, $actionMethod, null];
                    }
                }

                // 'Controller@method' OR 'Controller' (for __invoke)
                if ($value instanceof Node\Scalar\String_) {
                    if (str_contains($value->value, '@')) {
                        $parts = explode('@', $value->value, 2);

                        return [$parts[0], $parts[1], null];
                    }

                    return [$value->value, '__invoke', null];
                }

                // Controller::class (for __invoke)
                $classRef = $this->extractClassRef($value);
                if ($classRef !== '') {
                    return [$classRef, '__invoke', null];
                }

                // Closure routes
                if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) {
                    return ['Closure', '__invoke', $value];
                }

                return ['', '', null];
            }

            private function extractClassRef(Node $node): string
            {
                if ($node instanceof Node\Expr\ClassConstFetch) {
                    $class = $node->class;
                    if ($class instanceof Node\Name) {
                        $name = $class->toString();

                        // Return FQCN from use-map, not the short name
                        return $this->useMap[$name] ?? $name;
                    }
                }
                if ($node instanceof Node\Scalar\String_) {
                    return $node->value;
                }

                return '';
            }

            private function extractMiddlewareList(Node $node): array
            {
                if ($node instanceof Node\Scalar\String_) {
                    return [$node->value];
                }
                if ($node instanceof Node\Expr\Array_) {
                    $result = [];
                    foreach ($node->items as $item) {
                        if ($item && $item->value instanceof Node\Scalar\String_) {
                            $result[] = $item->value->value;
                        }
                    }

                    return $result;
                }

                return [];
            }

            private function extractString(?Node $node): ?string
            {
                if ($node === null) {
                    return null;
                }
                $value = $node instanceof Node\Arg ? $node->value : $node;
                if ($value instanceof Node\Scalar\String_) {
                    return $value->value;
                }
                if ($value instanceof Node\Expr\ArrayItem && $value->value instanceof Node\Scalar\String_) {
                    return $value->value->value;
                }

                return null;
            }

            private function resolveClass(Node $node): string
            {
                if ($node instanceof Node\Name) {
                    $name = $node->toString();
                    $resolved = $this->useMap[$name] ?? $name;
                    // Normalize to short name for Route facade matching
                    $parts = explode('\\', $resolved);

                    return end($parts);
                }

                return '';
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->routes;
    }
}
