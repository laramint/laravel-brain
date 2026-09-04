<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\MacroAnalyzer;
use LaraMint\LaravelBrain\Analysis\MacroDefinition;

/**
 * @filamerce-covers \LaraMint\LaravelBrain\Analysis\MacroAnalyzer
 */
function macros(): array
{
    return (new MacroAnalyzer(['app']))->analyze(fixture('macros-project'));
}

/** "Receiver::name" for every macro found, which is how a reader would name them. */
function macroNames(): array
{
    return array_map(
        static fn (MacroDefinition $m): string => class_basename($m->receiver).'::'.$m->name,
        macros(),
    );
}

function macroNamed(string $short): MacroDefinition
{
    foreach (macros() as $macro) {
        if (class_basename($macro->receiver).'::'.$macro->name === $short) {
            return $macro;
        }
    }

    throw new RuntimeException("no macro {$short}");
}

it('finds macros on a receiver that brings its own Macroable', function () {
    // The reason detection keys on the call and never on the receiver's traits: Filament ships a
    // Macroable of its own, so a check for Illuminate's would drop every component macro. The
    // fixture's Column declares its own static macro() and nothing else.
    expect(macroNames())->toContain('Column::labelIcon');
});

it('expands a mixin into the public methods it contributes', function () {
    // This is where the volume is: one registration, every public method of the class.
    expect(macroNames())
        ->toContain('Builder::withRevenue')
        ->toContain('Builder::withMargin');
});

it('reads both spellings of a mixin argument', function () {
    // `mixin(new X)` and `mixin(X::class)` are both accepted by Laravel, and the two Macroable
    // traits document different ones.
    expect(macroNamed('Builder::withRevenue')->mixin)->toBe('App\\Support\\OrderAnalytics')
        ->and(macroNamed('Collection::withRevenue')->mixin)->toBe('App\\Support\\OrderAnalytics');
});

it('contributes only what a caller could actually reach', function () {
    // A mixin gives instance methods. Protected helpers, statics and the constructor are not
    // methods the receiver gains, and reporting them would send a reader looking for a method
    // that does not exist.
    expect(macroNames())
        ->not->toContain('Builder::helper')
        ->not->toContain('Builder::build')
        ->not->toContain('Builder::__construct');
});

it('names the class the registration is written in', function () {
    // The payoff: a macro is invisible precisely because the method and the file that creates it
    // share nothing to search for.
    expect(macroNamed('Blueprint::money')->registrar)->toBe('App\\Providers\\AppMacrosProvider')
        ->and(macroNamed('Collection::withMargin')->registrar)->toBe('App\\Providers\\OtherMacrosProvider');
});

it('says nothing about a macro whose name it cannot read', function () {
    // `Blueprint::macro($name, …)` is a real registration whose method name is decided at
    // runtime. Reporting it under the variable's name would put a method in the graph that
    // nobody can call.
    expect(macroNames())->not->toContain('Blueprint::name')
        ->and(macroNames())->not->toContain('Blueprint::computed')
        // …and the ones either side of it in the same file are still read.
        ->and(macroNames())->toContain('Blueprint::money');
});

it('records where to look, down to the line', function () {
    $money = macroNamed('Blueprint::money');

    expect($money->file)->toEndWith('AppMacrosProvider.php')
        ->and($money->line)->toBeGreaterThan(0)
        ->and($money->origin())->toBe('macro');
});
