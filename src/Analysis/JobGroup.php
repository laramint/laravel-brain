<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * One `Bus::chain([...])` or `Bus::batch([...])` read off a dispatch site.
 *
 * ## Why a chain and a batch are one class and still not the same thing
 *
 * Both bound a set of jobs that were dispatched together, which is what makes them a region
 * rather than a node. Only one of them is a *sequence*: `Bus::chain([A, B, C])` runs A, then B,
 * then C, and B never runs if A fails, while a batch says nothing at all about who goes first.
 * So the order in {@see self::jobs()} is load-bearing for a chain and merely the order somebody
 * typed for a batch — the kind is what tells a reader which of the two they are looking at.
 *
 * ## The head is not always in the array
 *
 * Three of the five forms put the first job of the chain outside the array literal:
 *
 *     Bus::chain([new A, new B])          head is null; the array is the whole sequence
 *     A::withChain([new B])->dispatch()   head is A, and nothing else in the tracer will see it
 *     dispatch(new A)->chain([new B])     head is A, dispatched by the verb it is written on
 *
 * The last two differ in a way that matters to the caller and to nothing else: `dispatch(new A)`
 * is a dispatch in its own right and the tracer already records a hop for it, so recording one
 * here as well would draw the edge twice. `withChain` has no dispatch verb of its own around the
 * head, so if this does not report it, nothing does — hence {@see $headDispatchesItself} rather
 * than a rule that would have to be guessed at the call site.
 */
final class JobGroup
{
    /**
     * @param  'chain'|'batch'  $kind
     * @param  list<string>  $entries  the array literal's items, in the order they were written
     * @param  string|null  $head  the job the sequence starts on, when it sits outside the array
     * @param  bool  $headDispatchesItself  the head is already dispatched by the expression it is
     *                                      written on, so a second hop for it would double-count
     * @param  bool  $unresolved  an entry was not a literal this could read — `[$job]`, a factory
     *                            call, a spread — so the group is known to be incomplete
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $entries,
        public readonly ?string $head = null,
        public readonly bool $headDispatchesItself = false,
        public readonly bool $unresolved = false,
    ) {}

    /**
     * Every job in the group, in dispatch order.
     *
     * @return list<string>
     */
    public function jobs(): array
    {
        return $this->head === null ? $this->entries : [$this->head, ...$this->entries];
    }
}
