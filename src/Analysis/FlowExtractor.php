<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Converts a PHP method AST into a simplified list of flowchart steps.
 *
 * Each step is one of:
 *   { type: 'call',     label: string }
 *   { type: 'return',   label: string }
 *   { type: 'dispatch', label: string }
 *   { type: 'event',    label: string }
 *   { type: 'cache',    label: string, cache: array }
 *   { type: 'if',       label: string, then: step[], else: step[] }
 *   { type: 'loop',     label: string, body: step[] }
 *   { type: 'assign',   label: string }
 *   { type: 'throw',    label: string }
 *   { type: 'comment',  label: string }
 *
 * A step that makes an outgoing HTTP request also carries { http: HttpCall[] } — the same shape
 * `n1` has, and for the same reason: the fact belongs to the statement that causes it, so the
 * chart can mark that statement and the graph can collect the whole method's calls by walking the
 * steps it already built.
 */
class FlowExtractor
{
    private PrettyPrinter $printer;

    /** Null when cache-operation detection is off, so the work is never started. */
    private ?CacheOperationDetector $cacheDetector;

    private array $useMap;

    /**
     * Variable names bound by the foreach loops currently being descended into, innermost last.
     *
     * A relation read is only an N+1 when it is read off the thing the loop is iterating. Without
     * this the heuristic counted every property fetch, so `$this->service->handle()` inside any
     * loop registered as a query — the single largest source of false markers.
     *
     * @var list<string>
     */
    private array $loopVars = [];

    /**
     * @param  bool  $relationsAutoloaded  Whether the application batches relation access, which
     *                                     makes a relation read inside a loop not an N+1 at all.
     */
    private HttpCallExtractor $httpCalls;

    private bool $detectOutgoingHttp = true;

    public function __construct(private readonly bool $relationsAutoloaded = false)    {
        $this->printer = new PrettyPrinter;
        $this->cacheDetector = new CacheOperationDetector;
        $this->useMap = [];
        $this->httpCalls = new HttpCallExtractor;
    }

    /**
     * Turn cache-operation detection on or off.
     *
     * Off drops the detector rather than discarding its answers, so a project that does not want
     * the feature does not pay for it: {@see markCacheOperation()} and {@see tagCacheOperation()}
     * are on the path of every statement charted, and each one otherwise inspects the expression
     * and can reach the pretty printer to render a key.
     */
    public function setCacheOperationsEnabled(bool $enabled): void
    {
        $this->cacheDetector = $enabled ? ($this->cacheDetector ?? new CacheOperationDetector) : null;
    }

    /**
     * Whether to read each statement for calls that leave the application.
     *
     * Off is off: the scan does not run, rather than running and having its result dropped. This
     * walker sees every method in the project, so the difference is a whole pass over the source
     * for a project that asked not to have one — see `laravel-brain.outgoing_http.enabled`.
     */
    public function detectOutgoingHttp(bool $enabled): void
    {
        $this->detectOutgoingHttp = $enabled;
    }

    /**
     * Teach the outgoing-call classifier which method names build a request, so a call made
     * through one — `$this->client->api()->get('/me')` — is recognised even though the `Http`
     * facade it was built from is in another file.
     *
     * @param  array<string, array<string, mixed>>  $builders  method name => settings
     */
    public function setPendingRequestBuilders(array $builders): void
    {
        $this->httpCalls->setPendingRequestBuilders($builders);
    }

    /**
     * Extract flow steps from a method AST.
     *
     * @return array[]
     */
    public function extract(Node\Stmt\ClassMethod $method, array $useMap = []): array
    {
        $this->useMap = $useMap;
        $this->httpCalls->reset($useMap);

        return $this->stmtsToSteps($method->stmts ?? []);
    }

    /**
     * Extract flow steps from an inline closure or arrow function.
     * Arrow functions wrap their single expression in an implicit return step.
     *
     * @return array[]
     */
    public function extractFromClosure(
        Node\Expr\Closure|Node\Expr\ArrowFunction $closure,
        array $useMap = [],
    ): array {
        $this->useMap = $useMap;
        $this->httpCalls->reset($useMap);

        if ($closure instanceof Node\Expr\ArrowFunction) {
            // Arrow functions have a single expression body, no statement list
            return $this->stmtsToSteps([new Node\Stmt\Return_($closure->expr)]);
        }

        return $this->stmtsToSteps($closure->stmts ?? []);
    }

    /**
     * Compute static complexity metrics for a single method.
     *
     * Returns:
     *   lineCount            – physical lines (endLine - startLine + 1)
     *   statementCount       – number of top-level statements in the body
     *   cyclomaticComplexity – 1 + number of branching points (if/elseif/for/foreach/while/catch/case/&&/||)
     *   paramCount           – number of declared parameters
     */
    public function metrics(Node\Stmt\ClassMethod $method): array
    {
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $lineCount = ($startLine > 0 && $endLine >= $startLine) ? ($endLine - $startLine + 1) : 0;

        $stmts = $method->stmts ?? [];
        $statementCount = count($stmts);

        $cc = 1 + $this->countBranches($stmts);

        return [
            'lineCount' => $lineCount,
            'statementCount' => $statementCount,
            'cyclomaticComplexity' => $cc,
            'paramCount' => count($method->params),
        ];
    }

    /**
     * Recursively count branching nodes that increase cyclomatic complexity.
     *
     * @param  Node\Stmt[]  $stmts
     */
    private function countBranches(array $stmts): int
    {
        $count = 0;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\If_) {
                $count++; // the if itself
                $count += count($stmt->elseifs); // each elseif
                $count += $this->countBranches($stmt->stmts);
                foreach ($stmt->elseifs as $ei) {
                    $count += $this->countBranches($ei->stmts);
                }
                if ($stmt->else) {
                    $count += $this->countBranches($stmt->else->stmts);
                }
            } elseif ($stmt instanceof Node\Stmt\Foreach_) {
                $count++;
                $count += $this->countBranches($stmt->stmts);
            } elseif ($stmt instanceof Node\Stmt\For_) {
                $count++;
                $count += $this->countBranches($stmt->stmts);
            } elseif ($stmt instanceof Node\Stmt\While_) {
                $count++;
                $count += $this->countBranches($stmt->stmts);
            } elseif ($stmt instanceof Node\Stmt\Do_) {
                $count++;
                $count += $this->countBranches($stmt->stmts);
            } elseif ($stmt instanceof Node\Stmt\TryCatch) {
                $count += count($stmt->catches); // each catch adds a branch
                $count += $this->countBranches($stmt->stmts);
                foreach ($stmt->catches as $catch) {
                    $count += $this->countBranches($catch->stmts);
                }
            } elseif ($stmt instanceof Node\Stmt\Switch_) {
                foreach ($stmt->cases as $case) {
                    if ($case->cond !== null) {
                        $count++;
                    } // each non-default case
                    $count += $this->countBranches($case->stmts);
                }
            } elseif ($stmt instanceof Node\Stmt\Expression) {
                // Count short-circuit operators inside expressions
                $count += $this->countLogicalOps($stmt->expr);
            } elseif ($stmt instanceof Node\Stmt\Return_ && $stmt->expr !== null) {
                $count += $this->countLogicalOps($stmt->expr);
            }
        }

        return $count;
    }

    /**
     * Count && and || operators in an expression (each adds a branch).
     */
    private function countLogicalOps(Node\Expr $expr): int
    {
        $count = 0;
        if ($expr instanceof Node\Expr\BinaryOp\BooleanAnd
            || $expr instanceof Node\Expr\BinaryOp\BooleanOr
            || $expr instanceof Node\Expr\BinaryOp\LogicalAnd
            || $expr instanceof Node\Expr\BinaryOp\LogicalOr
        ) {
            $count++;
            $count += $this->countLogicalOps($expr->left);
            $count += $this->countLogicalOps($expr->right);
        } elseif ($expr instanceof Node\Expr\Ternary) {
            $count++;
        }

        return $count;
    }

    // ── Statement walker ──────────────────────────────────────────────────────

    /**
     * @param  Node\Stmt[]  $stmts
     * @return array[]
     */
    private function stmtsToSteps(array $stmts, bool $inLoop = false): array
    {
        $steps = [];
        foreach ($stmts as $stmt) {
            $step = $this->stmtToStep($stmt, $inLoop);
            if ($step !== null) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    private function stmtToStep(Node\Stmt $stmt, bool $inLoop = false): ?array
    {
        $step = $this->buildStep($stmt, $inLoop);

        return $step === null ? null : $this->withHttpCalls($step, $stmt);
    }

    /**
     * Record the outgoing HTTP requests a statement makes on the step it became.
     *
     * Only the expressions the statement owns are read — its condition, the thing it iterates,
     * the expression it is — never the statements nested inside it, because each of those becomes
     * a step of its own and is scanned when it does. Scanning both would report one request twice.
     *
     * The same reasoning decides whether to look inside closures, one statement at a time. A call
     * statement whose callback was charted (`retry(3, fn () => Http::get(...));`, which came back
     * with a `body`) has its requests reported by those steps; the identical expression assigned
     * to a variable is charted as a bare `assign`, nothing descends into it, and this is the only
     * place its request can be seen.
     *
     * The consequence worth knowing: a statement shape the chart does not draw at all (a `switch`
     * subject, an `elseif` condition, `$client?->get()` — a nullsafe call, which produces no step)
     * has nowhere to hang a call and so does not report one. The chart's vocabulary is the limit,
     * which is the trade for hanging the fact on the exact statement that causes it.
     */
    private function withHttpCalls(array $step, Node\Stmt $stmt): array
    {
        if (! $this->detectOutgoingHttp) {
            return $step;
        }

        $charted = $stmt instanceof Node\Stmt\Expression && isset($step['body']);

        $calls = [];
        foreach ($this->ownExpressions($stmt) as $expr) {
            foreach ($this->httpCalls->fromExpression($expr, ! $charted) as $call) {
                $calls[] = $call->toArray();
            }
        }

        return $calls === [] ? $step : $step + ['http' => $calls];
    }

    /**
     * The expressions a statement evaluates itself, as opposed to those inside its body.
     *
     * @return Node\Expr[]
     */
    private function ownExpressions(Node\Stmt $stmt): array
    {
        if ($stmt instanceof Node\Stmt\Expression) {
            return [$stmt->expr];
        }
        if ($stmt instanceof Node\Stmt\Return_) {
            return $stmt->expr !== null ? [$stmt->expr] : [];
        }
        if ($stmt instanceof Node\Stmt\If_) {
            return [$stmt->cond];
        }
        if ($stmt instanceof Node\Stmt\Foreach_) {
            return [$stmt->expr];
        }
        if ($stmt instanceof Node\Stmt\While_) {
            return [$stmt->cond];
        }
        if ($stmt instanceof Node\Stmt\For_) {
            return array_merge($stmt->init, $stmt->cond, $stmt->loop);
        }

        return [];
    }

    private function buildStep(Node\Stmt $stmt, bool $inLoop = false): ?array
    {
        // return $something;
        if ($stmt instanceof Node\Stmt\Return_) {
            $label = $stmt->expr !== null
                ? 'return '.$this->shortExpr($stmt->expr)
                : 'return';

            $step = ['type' => 'return', 'label' => $label];

            return $stmt->expr === null ? $step : $this->tagCacheOperation($step, $stmt->expr);
        }

        // if (...) { ... } else { ... }
        if ($stmt instanceof Node\Stmt\If_) {
            return $this->ifToStep($stmt, $inLoop);
        }

        // foreach (... as ...) { ... }
        if ($stmt instanceof Node\Stmt\Foreach_) {
            $expr = $this->shortExpr($stmt->expr);
            $value = $this->shortExpr($stmt->valueVar);
            $label = "foreach ({$expr} as {$value})";
            $this->loopVars[] = $this->rootVariableName($stmt->valueVar) ?? '';
            $body = $this->stmtsToSteps($stmt->stmts, true);
            $n1 = $this->hasQueryInside($stmt->stmts);
            array_pop($this->loopVars);

            return [
                'type' => 'loop',
                'label' => $label,
                'body' => $body,
                'n1' => $n1,
            ];
        }

        // for (...) / while (...) / do-while (...)
        if ($stmt instanceof Node\Stmt\For_) {
            $body = $this->stmtsToSteps($stmt->stmts, true);

            return [
                'type' => 'loop', 'label' => 'for (...)', 'body' => $body,
                'n1' => $this->hasQueryInside($stmt->stmts),
            ];
        }
        if ($stmt instanceof Node\Stmt\While_) {
            $body = $this->stmtsToSteps($stmt->stmts, true);

            return [
                'type' => 'loop', 'label' => 'while ('.$this->shortExpr($stmt->cond).')', 'body' => $body,
                'n1' => $this->hasQueryInside($stmt->stmts),
            ];
        }

        // try { ... } catch (...) { ... }
        if ($stmt instanceof Node\Stmt\TryCatch) {
            $inner = $this->stmtsToSteps($stmt->stmts, $inLoop);
            $catchSteps = [];
            foreach ($stmt->catches as $catch) {
                $exType = isset($catch->types[0]) ? $catch->types[0]->toString() : 'Exception';
                $catchSteps[] = ['type' => 'if', 'label' => "catch ({$exType})", 'then' => $this->stmtsToSteps($catch->stmts, $inLoop), 'else' => []];
            }

            return ['type' => 'loop', 'label' => 'try', 'body' => array_merge($inner, $catchSteps)];
        }

        // Expression statement: method calls, assignments, etc.
        if ($stmt instanceof Node\Stmt\Expression) {
            $step = $this->exprStmtToStep($stmt->expr);
            if ($step && $inLoop && $this->isQuery($stmt->expr)) {
                $step['n1'] = true;
            }

            return $step;
        }

        return null;
    }

    private function ifToStep(Node\Stmt\If_ $stmt, bool $inLoop = false): array
    {
        $cond = $this->shortExpr($stmt->cond);
        $then = $this->stmtsToSteps($stmt->stmts, $inLoop);

        $else = [];
        if ($stmt->else !== null) {
            $else = $this->stmtsToSteps($stmt->else->stmts, $inLoop);
        }
        foreach ($stmt->elseifs as $elseif) {
            $else = [['type' => 'if', 'label' => 'elseif ('.$this->shortExpr($elseif->cond).')',
                'then' => $this->stmtsToSteps($elseif->stmts, $inLoop), 'else' => $else]];
        }

        return [
            'type' => 'if',
            'label' => "if ({$cond})",
            'then' => $then,
            'else' => $else,
        ];
    }

    private function exprStmtToStep(Node\Expr $expr): ?array
    {
        // throw new SomeException(...)
        if ($expr instanceof Node\Expr\Throw_) {
            return ['type' => 'throw', 'label' => 'throw '.$this->shortExpr($expr->expr)];
        }

        // $var = value  /  $this->prop = value
        if ($expr instanceof Node\Expr\Assign) {
            $varLabel = $this->shortExpr($expr->var);
            $valLabel = $this->shortExpr($expr->expr);

            return $this->markCacheOperation(
                ['type' => 'assign', 'label' => "{$varLabel} = {$valLabel}"],
                $expr->expr,
            );
        }

        // SomeJob::dispatch(...)  →  dispatch
        if ($expr instanceof Node\Expr\StaticCall) {
            $class = $expr->class instanceof Node\Name ? $expr->class->toString() : '?';
            $method = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '?';
            $fqcn = $this->useMap[$class] ?? $class;
            $short = $this->baseName($fqcn);

            if ($method === 'dispatch' && (str_contains($fqcn, 'Job') || str_contains($fqcn, '\\Jobs\\'))) {
                return ['type' => 'dispatch', 'label' => "dispatch({$short})"];
            }
            if (in_array($class, ['Event', 'Illuminate\\Support\\Facades\\Event']) && $method === 'dispatch') {
                return ['type' => 'event', 'label' => 'Event::dispatch(...)'];
            }
            if (in_array($class, ['Bus', 'Illuminate\\Support\\Facades\\Bus']) && in_array($method, ['dispatch', 'dispatchSync', 'chain', 'batch'])) {
                return ['type' => 'dispatch', 'label' => "Bus::{$method}(...)"];
            }

            return $this->withCallbackBody(
                $this->markCacheOperation(['type' => 'call', 'label' => "{$short}::{$method}(...)"], $expr),
                $expr->args,
            );
        }

        // $this->service->method(...)  /  $var->method(...)
        if ($expr instanceof Node\Expr\MethodCall) {
            $m = $expr->name instanceof Node\Identifier ? $expr->name->toString() : null;
            if (in_array($m, ['dispatch', 'dispatchSync'], true)
                && $expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this') {
                return ['type' => 'dispatch', 'label' => $this->shortExpr($expr)];
            }

            return $this->withCallbackBody(
                $this->markCacheOperation(['type' => 'call', 'label' => $this->shortExpr($expr)], $expr),
                $expr->args,
            );
        }

        // event(new SomeEvent)  /  dispatch(new SomeJob)  /  dispatch_sync(new SomeJob)
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name) {
            $fn = $expr->name->toString();
            if ($fn === 'event') {
                return ['type' => 'event', 'label' => $this->shortExpr($expr)];
            }
            if (in_array($fn, ['dispatch', 'dispatch_sync'], true)) {
                return ['type' => 'dispatch', 'label' => $this->shortExpr($expr)];
            }

            return $this->withCallbackBody(
                $this->markCacheOperation(['type' => 'call', 'label' => $this->shortExpr($expr)], $expr),
                $expr->args,
            );
        }

        return null;
    }

    /**
     * Re-type a step as a cache step when its expression is a cache call, and hang the details
     * off it.
     *
     * Re-typing is what buys the distinct colour and icon — `call` and `assign` are both drawn as
     * plain rectangles, so nothing is lost by trading either for `cache`. A step that goes on to
     * pick up a callback body becomes a `loop`, because that is the only type either renderer
     * descends into; the `cache` payload rides along and still shows, which is what makes
     * `Cache::remember('k', 60, fn () => …)` readable as both a cache read and a block of work.
     *
     * @param  array{type: string, label: string}  $step
     * @return array<string, mixed>
     */
    private function markCacheOperation(array $step, Node\Expr $expr): array
    {
        if ($this->cacheDetector === null) {
            return $step;
        }

        $operation = $this->cacheDetector->detect($expr, $this->useMap);

        return $operation === null ? $step : ['type' => 'cache'] + $step + ['cache' => $operation->toArray()];
    }

    /**
     * Hang cache details off a step whose own type is load-bearing.
     *
     * A `return` is drawn as a terminal in both renderers and reads as the end of the flow;
     * `return Cache::get(...)` is still a return, so it keeps its shape and gains only the badge.
     *
     * @param  array{type: string, label: string}  $step
     * @return array<string, mixed>
     */
    private function tagCacheOperation(array $step, Node\Expr $expr): array
    {
        if ($this->cacheDetector === null) {
            return $step;
        }

        $operation = $this->cacheDetector->detect($expr, $this->useMap);

        return $operation === null ? $step : $step + ['cache' => $operation->toArray()];
    }

    /**
     * Give a call the steps of the callback it was passed, so the work inside is part of the flow.
     *
     * `DB::transaction(function () { ... })` is one statement holding a whole block of work, and
     * without this the flow chart shows the wrapper and stops — the body simply vanishes. The same
     * is true of `Cache::remember`, `retry`, a collection `each`, and anything else taking a
     * closure.
     *
     * The step becomes a `loop`, which is what the viewer calls a block with a body — both its
     * mermaid and React renderers descend into `body` for that type and for no other, so a `call`
     * carrying one would be dropped on the floor. A `try` block is already emitted this way for
     * the same reason.
     *
     * Only the first callback is descended into: a call taking two is rare, and showing one body
     * under one label is clearer than merging them.
     *
     * @param  array{type: string, label: string}  $step
     * @param  Node\Arg[]|Node\VariadicPlaceholder[]  $args
     * @return array<string, mixed>
     */
    /**
     * How deep this will follow callbacks into each other before it stops.
     *
     * Measured over three production applications: the deepest chart nests 5 levels, out of
     * 14,000-odd steps, and only 25 steps anywhere reach level 4 or 5. So this is roughly six
     * times what real code does, and it exists for source no one wrote by hand — FlowExtractor
     * runs over whatever is in the project, and generated or pathological files should not be
     * able to take a scan down.
     */
    private const MAX_CALLBACK_DEPTH = 32;

    private int $callbackDepth = 0;

    private function withCallbackBody(array $step, array $args): array
    {
        if ($this->callbackDepth >= self::MAX_CALLBACK_DEPTH) {
            return $step;
        }

        foreach ($args as $arg) {
            if (! $arg instanceof Node\Arg) {
                continue;
            }
            $value = $arg->value;
            $this->callbackDepth++;
            try {
                if ($value instanceof Node\Expr\Closure) {
                    $body = $this->stmtsToSteps($value->stmts);
                } elseif ($value instanceof Node\Expr\ArrowFunction) {
                    // An implicit return, matching how extractFromClosure() reads the same shape —
                    // otherwise one arrow function charts two ways depending on which path found it.
                    $body = $this->stmtsToSteps([new Node\Stmt\Return_($value->expr)]);
                } else {
                    continue;
                }
            } finally {
                $this->callbackDepth--;
            }

            return $body === [] ? $step : ['type' => 'loop'] + $step + ['body' => $body];
        }

        return $step;
    }

    // ── Expression prettifier ─────────────────────────────────────────────────

    /**
     * Convert an expression to a short human-readable string.
     */
    private function shortExpr(Node\Expr $expr): string
    {
        // $this->prop or $this->prop->chain
        if ($expr instanceof Node\Expr\PropertyFetch) {
            $obj = $this->shortExpr($expr->var);
            $prop = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '?';

            return "{$obj}->{$prop}";
        }

        // $var
        if ($expr instanceof Node\Expr\Variable) {
            return is_string($expr->name) ? '$'.$expr->name : '$?';
        }

        // $obj->method(args)
        if ($expr instanceof Node\Expr\MethodCall) {
            $obj = $this->shortExpr($expr->var);
            $method = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '?';
            $args = $this->argsLabel($expr->args);

            return "{$obj}->{$method}({$args})";
        }

        // Class::method(args)
        if ($expr instanceof Node\Expr\StaticCall) {
            $class = $expr->class instanceof Node\Name ? $this->baseName($expr->class->toString()) : '?';
            $method = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '?';
            $args = $this->argsLabel($expr->args);

            return "{$class}::{$method}({$args})";
        }

        // new Class(args)
        if ($expr instanceof Node\Expr\New_) {
            $class = $expr->class instanceof Node\Name ? $this->baseName($expr->class->toString()) : '?';

            return "new {$class}(...)";
        }

        // Function call
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name) {
            $fn = $expr->name->toString();
            $args = $this->argsLabel($expr->args);

            return "{$fn}({$args})";
        }

        // Scalar values
        if ($expr instanceof Node\Scalar\String_) {
            return "\"{$expr->value}\"";
        }
        if ($expr instanceof Node\Scalar\LNumber) {
            return (string) $expr->value;
        }
        if ($expr instanceof Node\Expr\ConstFetch) {
            return $expr->name->toString();
        }

        // Array access: $arr['key']
        if ($expr instanceof Node\Expr\ArrayDimFetch) {
            $var = $this->shortExpr($expr->var);
            $dim = $expr->dim !== null ? $this->shortExpr($expr->dim) : '';

            return "{$var}[{$dim}]";
        }

        // Ternary
        if ($expr instanceof Node\Expr\Ternary) {
            return $this->shortExpr($expr->cond).' ? ... : ...';
        }

        // Comparison / logical
        if ($expr instanceof Node\Expr\BinaryOp) {
            $left = $this->shortExpr($expr->left);
            $right = $this->shortExpr($expr->right);
            $op = $this->binaryOpSymbol($expr);

            return "{$left} {$op} {$right}";
        }

        if ($expr instanceof Node\Expr\BooleanNot) {
            return '!'.$this->shortExpr($expr->expr);
        }

        // A closure or arrow function used as a value — nearly always the callback a step was
        // built from. Without a case here it reaches the pretty printer below, which renders the
        // entire body: for nested callbacks that means re-rendering everything inside this one,
        // at every level, so the cost of labelling a chain grows with the square of its depth.
        if ($expr instanceof Node\Expr\Closure) {
            return 'function ('.$this->paramsLabel($expr->params).') {...}';
        }
        if ($expr instanceof Node\Expr\ArrowFunction) {
            return 'fn ('.$this->paramsLabel($expr->params).') => ...';
        }

        // Fallback: use pretty printer for full expression
        try {
            return $this->printer->prettyPrintExpr($expr);
        } catch (\Throwable) {
            return '...';
        }
    }

    /**
     * The parameter names of a closure, which is the part of its signature worth keeping in a
     * label. The body is what gets dropped: it is either charted underneath the step already, or
     * it is an assignment whose contents belong in the code rather than in a chart label.
     *
     * @param  Node\Param[]  $params
     */
    private function paramsLabel(array $params): string
    {
        $names = [];
        foreach ($params as $param) {
            $names[] = $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                ? '$'.$param->var->name
                : '$?';
        }

        return implode(', ', $names);
    }

    private function argsLabel(array $args): string
    {
        if (empty($args)) {
            return '';
        }
        if (count($args) === 1) {
            $arg = $args[0];
            if ($arg instanceof Node\Arg) {
                return $this->shortExpr($arg->value);
            }
        }

        return '...';
    }

    private function binaryOpSymbol(Node\Expr\BinaryOp $op): string
    {
        return match (true) {
            $op instanceof Node\Expr\BinaryOp\Equal => '==',
            $op instanceof Node\Expr\BinaryOp\Identical => '===',
            $op instanceof Node\Expr\BinaryOp\NotEqual => '!=',
            $op instanceof Node\Expr\BinaryOp\NotIdentical => '!==',
            $op instanceof Node\Expr\BinaryOp\Greater => '>',
            $op instanceof Node\Expr\BinaryOp\GreaterOrEqual => '>=',
            $op instanceof Node\Expr\BinaryOp\Smaller => '<',
            $op instanceof Node\Expr\BinaryOp\SmallerOrEqual => '<=',
            $op instanceof Node\Expr\BinaryOp\BooleanAnd => '&&',
            $op instanceof Node\Expr\BinaryOp\BooleanOr => '||',
            $op instanceof Node\Expr\BinaryOp\Plus => '+',
            $op instanceof Node\Expr\BinaryOp\Minus => '-',
            $op instanceof Node\Expr\BinaryOp\Concat => '.',
            default => '?',
        };
    }

    private function hasQueryInside(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Expression) {
                if ($this->isQuery($stmt->expr)) {
                    return true;
                }
            }
            if ($stmt instanceof Node\Stmt\If_) {
                if ($this->hasQueryInside($stmt->stmts)) {
                    return true;
                }
                if ($stmt->else !== null && $this->hasQueryInside($stmt->else->stmts)) {
                    return true;
                }
                foreach ($stmt->elseifs as $elseif) {
                    if ($this->hasQueryInside($elseif->stmts)) {
                        return true;
                    }
                }
            }
            if ($stmt instanceof Node\Stmt\Foreach_ && $this->hasQueryInside($stmt->stmts)) {
                return true;
            }
            if ($stmt instanceof Node\Stmt\For_ && $this->hasQueryInside($stmt->stmts)) {
                return true;
            }
            if ($stmt instanceof Node\Stmt\While_ && $this->hasQueryInside($stmt->stmts)) {
                return true;
            }
            if ($stmt instanceof Node\Stmt\TryCatch && $this->hasQueryInside($stmt->stmts)) {
                return true;
            }
        }

        return false;
    }

    private function isQuery(Node\Expr $expr): bool
    {
        // $order->customer — a relation read, but only where `$order` is what the loop is
        // iterating. Any property fetch used to answer yes here, including the receiver of an
        // ordinary call reached through the chain below: `$this->service->handle()` in a loop was
        // counted as a query. Measured on a real application, that one branch produced 28 of 33
        // markers.
        //
        // Where the application autoloads relations, even the genuine shape is batched, so there
        // is no per-iteration query left to warn about.
        if ($expr instanceof Node\Expr\PropertyFetch) {
            if ($this->relationsAutoloaded) {
                return false;
            }

            $root = $this->rootVariableName($expr);

            return $root !== null && in_array($root, $this->loopVars, true);
        }

        // $model->save() / $model->relation()->get()
        if ($expr instanceof Node\Expr\MethodCall) {
            $name = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '';
            $queryMethods = [
                'get', 'first', 'find', 'all', 'paginate', 'simplePaginate', 'cursor',
                'save', 'update', 'delete', 'create', 'push', 'touch', 'sync', 'attach', 'detach', 'toggle',
            ];
            if (in_array($name, $queryMethods)) {
                return true;
            }

            // Chain: $q->where(...)->get()
            if ($this->isQuery($expr->var)) {
                return true;
            }
        }

        // Model::find()
        if ($expr instanceof Node\Expr\StaticCall) {
            $name = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '';

            return in_array($name, ['find', 'first', 'all', 'where', 'create', 'updateOrCreate', 'firstOrCreate']);
        }

        // $var = query
        if ($expr instanceof Node\Expr\Assign) {
            return $this->isQuery($expr->expr);
        }

        return false;
    }

    /**
     * The variable a fetch chain is rooted at: `$order->customer->address` is rooted at `order`.
     *
     * Null for anything not ultimately rooted in a plain variable — `$this`, a static call, a
     * function result — none of which is the thing a foreach binds.
     */
    private function rootVariableName(Node\Expr $expr): ?string
    {
        while ($expr instanceof Node\Expr\PropertyFetch || $expr instanceof Node\Expr\NullsafePropertyFetch) {
            $expr = $expr->var;
        }

        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return $expr->name;
        }

        return null;
    }

    private function baseName(string $fqcn): string
    {
        $short = $this->useMap[$fqcn] ?? $fqcn;
        $parts = explode('\\', $short);

        return end($parts);
    }
}
