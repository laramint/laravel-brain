<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use PhpParser\Node;

/**
 * Parks the cadence chain of a scheduling call under the call it was written on.
 *
 * The cadence is stated by the calls wrapped AROUND the registration
 * (`Schedule::command('x')->dailyAt('05:30')`), and a node cannot see its own parents.
 * Traversal is top-down, so the chain is read on the way in — from the outermost call, which
 * is the only one that sees the whole tail — and parked for the registration that arrives a
 * few nodes later.
 *
 * Both scheduling spellings park in the same index. That matters: the two used to read the
 * chain separately, and the `Kernel::schedule()` spelling read it in the one direction that
 * cannot work. `$schedule->command('x')->daily()` puts the registration INSIDE the cadence
 * call, so walking outward from `command` reaches only `$schedule` — every legacy-kernel
 * entry was recorded with an empty frequency, and no test covered that path.
 */
class ScheduleChainIndex
{
    /**
     * Methods that register a task. Walking a chain inward stops here: everything further in
     * belongs to the registration, not to its cadence.
     *
     * @var string[]
     */
    public const REGISTRATION_METHODS = ['command', 'job', 'call'];

    /** @var array<int, ScheduleChain> spl_object_id of a registration call => the chain wrapping it */
    private array $chains = [];

    /**
     * Read the chain `$node` sits at the top of and park it under the registration it wraps.
     *
     * Called for every method call in the file; one that bottoms out somewhere other than a
     * scheduling registration is simply dropped.
     */
    public function remember(Node\Expr\MethodCall $node): void
    {
        $chain = new ScheduleChain;
        $current = $node;

        while ($current instanceof Node\Expr\MethodCall) {
            $name = $current->name instanceof Node\Identifier ? $current->name->toString() : '';

            if (in_array($name, self::REGISTRATION_METHODS, true)) {
                break;
            }

            if ($name === 'timezone') {
                // Not a cadence, though it reads like one. Left in the frequency list it won
                // the walk outright, so `->daily()->timezone('Europe/Warsaw')` was recorded as
                // running with a frequency of "timezone".
                $chain->timezone = self::literalArguments($current)[0] ?? '';
            } elseif (in_array($name, ConsoleAnalyzer::FREQUENCY_METHODS, true)) {
                // Assigned unconditionally, so the innermost cadence — the one written first,
                // closest to the registration — is the one kept. That is the base cadence;
                // anything wrapped around it refines it.
                $chain->frequency = $name;
                $chain->frequencyArguments = self::literalArguments($current);
            } elseif (in_array($name, ConsoleAnalyzer::MODIFIER_METHODS, true)) {
                array_unshift($chain->modifiers, $name);
            }

            $current = $current->var;
        }

        if (! $this->isRegistration($current)) {
            return;
        }

        // First write wins: the outermost call is entered first and is the only one that saw
        // the whole tail, so a shorter reading from further in must not replace it.
        $this->chains[spl_object_id($current)] ??= $chain;
    }

    /** The chain written on a registration call, or an empty one when it carries no tail. */
    public function for(Node $registration): ScheduleChain
    {
        return $this->chains[spl_object_id($registration)] ?? new ScheduleChain;
    }

    private function isRegistration(Node $node): bool
    {
        if ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) {
            $name = $node->name instanceof Node\Identifier ? $node->name->toString() : '';

            return in_array($name, self::REGISTRATION_METHODS, true);
        }

        return false;
    }

    /**
     * The literal arguments of a cadence call, in order.
     *
     * Collection stops at the first argument that is not a literal: a partial list rendered as
     * "dailyAt 05:30" would read as a fact, and `dailyAt($time)` is not one.
     *
     * @return string[]
     */
    private static function literalArguments(Node\Expr\MethodCall $node): array
    {
        $arguments = [];

        foreach ($node->args as $arg) {
            if (! $arg instanceof Node\Arg) {
                return $arguments;
            }

            $value = $arg->value;

            if ($value instanceof Node\Scalar\String_) {
                $arguments[] = $value->value;
            } elseif ($value instanceof Node\Scalar\Int_) {
                $arguments[] = (string) $value->value;
            } elseif ($value instanceof Node\Expr\ConstFetch) {
                $arguments[] = $value->name->toString();
            } else {
                return $arguments;
            }
        }

        return $arguments;
    }
}
