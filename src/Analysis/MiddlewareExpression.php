<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use PhpParser\Node;

/**
 * The middleware string a route file builds instead of writing out: the framework's own factories
 * (`Authenticate::using('api')`) and a class name with parameters joined on
 * (`Authenticate::class.':api'`). Both give the `Class:parameters` string the router would see.
 *
 * Parameters are kept only when all of them are literals. The name is what decides a route's
 * exposure, so it is kept either way.
 */
class MiddlewareExpression
{
    /**
     * Static methods that return `static::class` with parameters. Any other static call can return
     * anything, a list of other middleware included, so its class is not read as one.
     */
    private const FACTORIES = ['using', 'with', 'redirectTo', 'relative', 'absolute'];

    /**
     * @param  callable(Node\Name): string  $className  Resolves a class name as the caller's file sees it.
     */
    public static function resolve(Node $node, callable $className): ?string
    {
        if ($node instanceof Node\Expr\StaticCall) {
            return self::fromFactory($node, $className);
        }

        return $node instanceof Node\Expr\BinaryOp\Concat ? self::fromConcat($node, $className) : null;
    }

    private static function fromFactory(Node\Expr\StaticCall $call, callable $className): ?string
    {
        if (! $call->class instanceof Node\Name
            || ! $call->name instanceof Node\Identifier
            || ! in_array($call->name->toString(), self::FACTORIES, true)
            || $call->isFirstClassCallable()
        ) {
            return null;
        }

        $class = $className($call->class);
        if ($class === '') {
            return null;
        }

        $method = $call->name->toString();
        $parameters = $method === 'relative' ? ['relative'] : [];

        foreach ($call->args as $arg) {
            $literal = $arg instanceof Node\Arg && ! $arg->unpack && $arg->name === null ? self::literal($arg->value) : null;
            if ($literal === null) {
                return $class;
            }
            $parameters[] = $literal;
        }

        return $parameters === [] ? $class : $class.':'.implode(',', $parameters);
    }

    /**
     * Read from the left up to the first part that is not a literal. What stands there by then is a
     * middleware name only when the `:` that ends a name came before it.
     */
    private static function fromConcat(Node\Expr\BinaryOp\Concat $concat, callable $className): ?string
    {
        $middleware = '';

        foreach (self::parts($concat) as $part) {
            $literal = self::literal($part);
            if ($literal === null
                && $middleware === ''
                && $part instanceof Node\Expr\ClassConstFetch
                && $part->class instanceof Node\Name
                && $part->name instanceof Node\Identifier
                && $part->name->toLowerString() === 'class'
            ) {
                $literal = $className($part->class);
            }

            if ($literal === null) {
                $name = explode(':', $middleware, 2);

                return count($name) === 2 && $name[0] !== '' ? $name[0] : null;
            }

            $middleware .= $literal;
        }

        return $middleware === '' ? null : $middleware;
    }

    /**
     * @return list<Node\Expr>
     */
    private static function parts(Node\Expr $expr): array
    {
        return $expr instanceof Node\Expr\BinaryOp\Concat
            ? [...self::parts($expr->left), ...self::parts($expr->right)]
            : [$expr];
    }

    private static function literal(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_ || $expr instanceof Node\Scalar\Int_) {
            return (string) $expr->value;
        }

        return null;
    }
}
