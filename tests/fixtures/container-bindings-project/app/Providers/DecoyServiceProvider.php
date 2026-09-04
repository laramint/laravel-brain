<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Ledger;
use App\Support\Reporter;
use App\Support\SystemClock;
use Illuminate\Support\ServiceProvider;

/**
 * Calls spelled like a container registration that are not one. Nothing in this provider may
 * reach the registry at all — a test asserts no record carries this provider's name.
 */
class DecoyServiceProvider extends ServiceProvider
{
    /** @var object */
    protected $collection;

    public function register(): void
    {
        // Receiver is not the container: a property that is not `app`, and a variable that is
        // not `$app`. Both would be recorded if the single-argument form trusted the method
        // name alone.
        $this->collection->bind(Reporter::class);
        $this->collection->singleton(Reporter::class, Ledger::class);

        $routes = $this->collection;
        $routes->scoped(Reporter::class);

        // Single argument that is not class-shaped: a bare key binds to nothing resolvable, and
        // this is the shape an unrelated one-argument `bind()` most often takes.
        $this->app->bind('unresolvable-on-its-own');

        // Not an identifier a container key can be: the charset rule rejects it rather than
        // filing a sentence as a binding.
        $this->app->singleton('a key with spaces', SystemClock::class);

        // A spread hides how many arguments follow it. Reading the abstract and stopping would
        // record this as a self-binding of Ledger — a concrete WAS written, it just cannot be
        // read — so the whole call is given up instead.
        $rest = [Reporter::class];
        $this->app->bind(Ledger::class, ...$rest);

        // First-class callable syntax: the argument list holds a placeholder, not an argument.
        $binder = $this->app->bind(...);
        $binder(Reporter::class, Ledger::class);
    }
}
