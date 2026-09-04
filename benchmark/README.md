# Benchmark suite

What a scan costs, and what it detects, measured on a Laravel application this
directory generates. Every pull request that touches `src/`, `benchmark/` or the
Composer files gets the comparison posted as a comment.

## Running it

```bash
composer benchmark                       # table on stderr
php benchmark/benchmark.php --json       # machine-readable
php benchmark/benchmark.php --markdown   # markdown table
```

Useful options:

| Option | Meaning |
|---|---|
| `--reps=N` | timed repetitions per scenario (default 5) |
| `--warmups=N` | untimed repetitions first (default 1) |
| `--scenario=NAME` | run one scenario only |
| `--corpus-dir=DIR` | where the generated applications live (default: system temp) |
| `--out=FILE` | write the JSON to a file instead of stdout |

The generated applications are cached in `--corpus-dir` and keyed by the
generator's own hash, so editing `generate-corpus.php` regenerates them rather
than silently reusing a stale tree.

## Scenarios

| Scenario | What it runs |
|---|---|
| `scan-small` | `ProjectAnalyzer::analyze()` over ~400 PHP files |
| `scan-large` | the same over ~1,200 PHP files, which catches costs that grow faster than the file count |
| `trace-methods` | `MethodTracer` over every entry-point method of the ~400-file tree — the call-tracing half of a build, isolated from the rest |

A default run — three scenarios, one warmup and five timed repetitions each —
takes a few seconds once the applications have been generated.

The application is synthetic and deliberately awkward: a service, repository and
model layer shared by many entry points, chains four deep, classes with private
helpers and call-free getters, two application facades, and a framework facade
import on almost every class so the prefilter has something to skip. It is a
workload, not an average app, so read the absolute times as such.

## Reading the two kinds of number

**Counts** — nodes, edges, tabs, routes, security issues, `parse()` calls, and a
per-node-type breakdown. Deterministic: the same code over the same generated
tree produces the same figures, whatever the tree's path and however many times
it is scanned — the runner asserts that across repetitions and says so in the
comment if it ever fails to hold. Both CI arms scan one tree on one pinned PHP
version, so a difference belongs to the change. That is worth looking at, not
automatically worth fixing: an analyzer that detects more will move them by
design.

**Timing** — a median over repetitions. Wall clock on a shared runner is noisy
enough to invent a result, so the comparison reports how far the base arm's own
repetitions sat from its median — discounting the single worst on each side —
marks any delta inside that as not measurable, and prints their full spread next
to it. Discounting rather than taking the extremes: one stalled repetition would
otherwise set a floor that hides every real regression behind it.

The phase split gets the same treatment per phase, from that phase's own
repetitions, because a phase is noisier than the scan containing it. On an
unchanged pull request the facade phase has been seen to range over 2x between
repetitions of identical code; without its own floor it would read as a 50%
improvement.

`parse()` calls are the stable companion to a timing claim — a change in speed
with the parse count unmoved is good evidence that only speed moved.

A note on the guards in `benchmark.php`: it feature-detects the caches it
clears (`method_exists`, `property_exists`) because it also runs against *other*
checkouts of `src/` — the merge base's, in CI — where a given cache may not exist
yet. Static analysis of this checkout cannot see that, which is why `benchmark/`
is deliberately outside `phpstan.neon`'s paths.

## How the comparison runs in CI

`benchmark.yml` checks out the pull request and its **merge base** — not the
current tip of the base branch, which would report other people's merges as this
pull request's differences — installs both, generates the applications once, and
alternates the arms round by round so machine load lands on both. The pull
request's own copy of the benchmark measures both arms: `BRAIN_BENCH_AUTOLOAD`
points `bootstrap.php` at each checkout's `vendor/autoload.php` in turn, so the
harness code is identical and only `src/` differs.

`compare.php` then renders the comment. `benchmark-comment.yml` posts it from a
separate `workflow_run` job, because the job that runs pull request code must not
hold a token that can write to the pull request.

To reproduce a CI comparison locally:

```bash
BASE="$(git merge-base HEAD origin/main)"
git worktree add --detach ../brain-base "$BASE"
(cd ../brain-base && composer install)
mkdir -p /tmp/arm-base /tmp/arm-head

for round in 1 2 3 4 5; do
  BRAIN_BENCH_AUTOLOAD="$(cd ../brain-base && pwd)/vendor/autoload.php" \
    php benchmark/benchmark.php --reps=2 --corpus-dir=/tmp/brain-corpora \
      --out="/tmp/arm-base/round-$round.json"

  BRAIN_BENCH_AUTOLOAD="$PWD/vendor/autoload.php" \
    php benchmark/benchmark.php --reps=2 --corpus-dir=/tmp/brain-corpora \
      --out="/tmp/arm-head/round-$round.json"
done

php benchmark/compare.php /tmp/arm-base /tmp/arm-head
```

Build the base arm from a separate checkout, never with `git stash` — once the
work is committed, `stash` reverts nothing and succeeds, and both arms end up
running the same code.
