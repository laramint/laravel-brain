<?php

use LaraMint\LaravelBrain\Analysis\TransactionScopes;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** Parse a method body and hand back both the tree and the scopes found in it. */
function scopesFor(string $body): array
{
    $code = "<?php\nclass Probe {\n    public function handle(): void\n    {\n{$body}\n    }\n}\n";
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code) ?? [];

    $method = (new NodeFinder)->findFirstInstanceOf($ast, Node\Stmt\ClassMethod::class);
    expect($method)->not->toBeNull();

    return [$method, TransactionScopes::in($method)];
}

/** The call to `$name(...)` inside the parsed body. */
function callTo(Node $method, string $name): Node
{
    $found = (new NodeFinder)->findFirst([$method], function (Node $node) use ($name): bool {
        $called = match (true) {
            $node instanceof Node\Expr\StaticCall,
            $node instanceof Node\Expr\MethodCall => $node->name instanceof Node\Identifier ? $node->name->toString() : null,
            default => null,
        };

        return $called === $name;
    });

    expect($found)->not->toBeNull();

    return $found;
}

it('puts the body of a closure transaction inside the span', function () {
    [$method, $scopes] = scopesFor('        DB::transaction(function () { $this->writeLedger(); });');

    expect($scopes->isInTransaction(callTo($method, 'writeLedger')))->toBeTrue();
});

it('reads an arrow function the same way', function () {
    [$method, $scopes] = scopesFor('        DB::transaction(fn () => $this->writeLedger());');

    expect($scopes->isInTransaction(callTo($method, 'writeLedger')))->toBeTrue();
});

it('follows a transaction opened on a named connection', function () {
    [$method, $scopes] = scopesFor('        DB::connection("tenant")->transaction(function () { $this->writeLedger(); });');

    expect($scopes->isInTransaction(callTo($method, 'writeLedger')))->toBeTrue();
});

it('takes the statements between beginTransaction and commit', function () {
    [$method, $scopes] = scopesFor(<<<'PHP'
            DB::beginTransaction();
            $this->writeLedger();
            DB::commit();
            $this->afterwards();
    PHP);

    expect($scopes->isInTransaction(callTo($method, 'writeLedger')))->toBeTrue()
        ->and($scopes->isInTransaction(callTo($method, 'afterwards')))->toBeFalse();
});

it('closes the span at a rollback as well as at a commit', function () {
    [$method, $scopes] = scopesFor(<<<'PHP'
            DB::beginTransaction();
            $this->writeLedger();
            DB::rollBack();
            $this->afterwards();
    PHP);

    expect($scopes->isInTransaction(callTo($method, 'afterwards')))->toBeFalse();
});

it('marks a catch block that rolls back as the compensation path', function () {
    // What runs here runs with the transaction already gone: a write survives, and an event
    // announces a failure rather than a fact. That is a different claim from "inside the span".
    [$method, $scopes] = scopesFor(<<<'PHP'
            try {
                DB::beginTransaction();
                $this->writeLedger();
                DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();
                $this->notifyFailure();
            }
    PHP);

    expect($scopes->isInRollback(callTo($method, 'notifyFailure')))->toBeTrue()
        ->and($scopes->isInRollback(callTo($method, 'writeLedger')))->toBeFalse();
});

it('does not treat some other object\'s transaction() as a database one', function () {
    // `transaction()` is not a reserved word. A domain service with a method of that name would
    // otherwise put half a method inside a span that does not exist.
    [$method, $scopes] = scopesFor('        $this->ledgerService->transaction(function () { $this->writeLedger(); });');

    expect($scopes->isInTransaction(callTo($method, 'writeLedger')))->toBeFalse();
});

it('leaves a method with no transaction entirely unmarked', function () {
    [, $scopes] = scopesFor('        $this->writeLedger();');

    expect($scopes->hasAny())->toBeFalse();
});

it('does not run a span past the end of the block that opened it', function () {
    // A `beginTransaction()` inside an `if` closes with that branch. Treating the method as one
    // flat sequence would swallow everything after the block.
    [$method, $scopes] = scopesFor(<<<'PHP'
            if ($flag) {
                DB::beginTransaction();
                $this->writeLedger();
                DB::commit();
            }
            $this->afterwards();
    PHP);

    expect($scopes->isInTransaction(callTo($method, 'writeLedger')))->toBeTrue()
        ->and($scopes->isInTransaction(callTo($method, 'afterwards')))->toBeFalse();
});
