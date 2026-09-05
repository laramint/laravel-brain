<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * Counts how many commits touched each file in a bounded recent window, in one `git log`
 * invocation for the whole project — mirroring {@see ExternalSecurityScanner}'s "one
 * subprocess call, results keyed by file" shape rather than {@see GitHistoryInspector}'s
 * one-call-per-file shape. That distinction is deliberate: no analyzer pass in this codebase
 * skips itself on a scoped rescan (table_stats and schema re-run in full too), so a per-file
 * loop here would mean one subprocess per tracked file on every full scan and every
 * watch-mode poll. A single bulk call keeps the cost fixed regardless of project size.
 */
class GitChurnAnalyzer
{
    /**
     * @return array<string, array{commitCount: int, lastChangedAt: string, lastAuthor: string}> absolute file
     *                                                                                           path => commits touching it within `$since`, and the most recent one's date
     *                                                                                           (YYYY-MM-DD). Absolute paths are built from git's own `--relative` output
     *                                                                                           joined onto $projectRoot, so they match `data.file` exactly as GraphBuilder
     *                                                                                           writes it — without --relative, git reports paths relative to the repository's
     *                                                                                           top level regardless of cwd, which would silently produce wrong keys (and an
     *                                                                                           empty-looking ranking) whenever the scanned project is not itself the git root.
     *
     *         Empty when: git/binary unavailable, not a git repository, or no commits in the
     *         window. Never null — an empty map degrades a ranked list to "nothing ranked"
     *         and a per-node stamp to "nothing stamped", both correct outcomes rather than
     *         failures worth a caller branching on.
     */
    public function scan(string $projectRoot, string $since, int $maxOutputBytes): array
    {
        $cmd = [
            'git', '--no-pager', 'log', '--no-merges', '--relative',
            '--since', $since,
            '--name-only', '--format=@@%ad%x1f%an', '--date=short',
        ];

        $result = $this->run($cmd, $projectRoot, $maxOutputBytes);

        if ($result === null || $result['exitCode'] !== 0 || trim($result['stdout']) === '') {
            return [];
        }

        return $this->parse($result['stdout'], $result['truncated'], rtrim($projectRoot, '/'));
    }

    /**
     * @return array<string, array{commitCount: int, lastChangedAt: string, lastAuthor: string}>
     */
    private function parse(string $stdout, bool $truncated, string $root): array
    {
        $lines = explode("\n", $stdout);
        if ($truncated) {
            // The last line may be a filename or date cut mid-byte by the read cap.
            array_pop($lines);
        }

        $byFile = [];
        $currentDate = null;
        $currentAuthor = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '@@')) {
                [$currentDate, $currentAuthor] = array_pad(explode("\x1f", substr($line, 2), 2), 2, '');

                continue;
            }
            if ($currentDate === null) {
                continue;
            }

            $absolute = $root.'/'.$line;
            $byFile[$absolute]['commitCount'] = ($byFile[$absolute]['commitCount'] ?? 0) + 1;
            // First time seen wins: git log is newest-first, so the first commit touching a
            // given file in this stream is that file's most recent change in the window.
            $byFile[$absolute]['lastChangedAt'] ??= $currentDate;
            $byFile[$absolute]['lastAuthor'] ??= $currentAuthor;
        }

        return $byFile;
    }

    /**
     * @param  list<string>  $cmd
     * @return array{stdout: string, stderr: string, exitCode: int, truncated: bool}|null
     */
    private function run(array $cmd, string $cwd, int $maxOutputBytes): ?array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd);
        if (! is_resource($proc)) {
            return null;
        }

        fclose($pipes[0]);

        $stdout = '';
        $truncated = false;

        while (! feof($pipes[1])) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            if ($truncated) {
                continue; // keep draining to EOF; stop accumulating
            }
            if (strlen($stdout) + strlen($chunk) > $maxOutputBytes) {
                $stdout .= substr($chunk, 0, $maxOutputBytes - strlen($stdout));
                $truncated = true;
            } else {
                $stdout .= $chunk;
            }
        }
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $exitCode = proc_close($proc);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => $exitCode, 'truncated' => $truncated];
    }
}
