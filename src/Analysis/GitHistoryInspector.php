<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * Reads the last commit that touched a single file: who committed it, the diff that commit
 * introduced, and the full before/after content of the file at that commit — via `git log -1
 * -p` for the first two (one invocation gives both, including the file's first-ever commit,
 * which git renders as an addition against /dev/null natively, no special-casing needed) and
 * two `git show <rev>:<path>` calls for the full content a side-by-side view needs.
 *
 * Mirrors ExternalSecurityScanner's proc_open pattern (array-form command, no shell string,
 * stdin closed immediately, failures collapse to null, no timeout) with two deviations:
 *   - stderr is captured rather than discarded, so "not a git repository" is distinguishable
 *     from other failures if this is ever inspected during debugging — the public contract
 *     still collapses everything to null.
 *   - stdout is drained in a bounded loop rather than one stream_get_contents() call, because
 *     unlike a JSON security report, git output here has no natural size ceiling (a huge
 *     generated file's first commit). The loop still reads to EOF unconditionally so the
 *     child process is never left blocked on a full pipe — it just stops *accumulating* past
 *     the cap.
 */
class GitHistoryInspector
{
    /**
     * Any single piece of git output (the diff, or either side's full file content) beyond
     * this is cut off with a trailing marker. 200,000 bytes matches the existing ceiling on
     * exported context (BrainController::context()'s budget maxes at 50,000 tokens x 4
     * chars/token), rather than inventing a new number.
     */
    private const MAX_OUTPUT_BYTES = 200_000;

    /**
     * @return array{hash: string, shortHash: string, authorName: string, authorEmail: string, date: string, subject: string, diff: string, truncated: bool, oldContent: string|null, newContent: string|null, oldContentTruncated: bool, newContentTruncated: bool, remoteCommitUrl: string|null}|null
     *
     * null when: git/binary unavailable, not a git repository, or the file has no commits
     * (untracked / never committed). Callers must pass an already-validated, realpath'd,
     * project-root-contained absolute path — this method does not re-validate containment,
     * matching how source() only reads after its own check.
     *
     * oldContent is null for the file's first commit (there is no previous version) rather
     * than an error — every other failure to read a side's content also degrades to null
     * for that side alone, so a side-by-side view can still show whichever side did resolve.
     */
    public function lastCommit(string $absoluteFilePath, string $projectRoot): ?array
    {
        $cmd = [
            'git', '--no-pager', 'log', '-1', '-p',
            '--format=%H%x1f%an%x1f%ae%x1f%ad%x1f%s',
            '--date=iso-strict',
            '--', $absoluteFilePath,
        ];

        $result = $this->run($cmd, $projectRoot);

        if ($result === null || $result['exitCode'] !== 0 || trim($result['stdout']) === '') {
            return null;
        }

        $commit = $this->parse($result['stdout'], $result['truncated']);
        $relative = $this->relativePath($absoluteFilePath, $projectRoot);

        [$commit['newContent'], $commit['newContentTruncated']] = $this->readBlob($commit['hash'].':'.$relative, $projectRoot);
        [$commit['oldContent'], $commit['oldContentTruncated']] = $this->readBlob($commit['hash'].'^:'.$relative, $projectRoot);
        $commit['remoteCommitUrl'] = $this->remoteCommitUrl($commit['hash'], $projectRoot);

        return $commit;
    }

    /**
     * The URL for viewing this commit on whatever host `origin` points at — GitHub, GitLab,
     * Bitbucket, or a self-hosted instance of any of them (detected by a substring match on
     * the host, since a self-hosted GitLab commonly names itself gitlab.<company>.com rather
     * than gitlab.com). Null when there is no `origin` remote or its URL does not resolve to
     * a host and path this can template a URL from — never a reason to fail the rest of
     * lastCommit().
     */
    private function remoteCommitUrl(string $hash, string $projectRoot): ?string
    {
        $result = $this->run(['git', 'remote', 'get-url', 'origin'], $projectRoot);
        if ($result === null || $result['exitCode'] !== 0) {
            return null;
        }

        $remote = $this->parseRemote(trim($result['stdout']));
        if ($remote === null) {
            return null;
        }
        [$host, $path] = $remote;

        if (str_contains($host, 'gitlab')) {
            return "https://{$host}/{$path}/-/commit/{$hash}";
        }
        if (str_contains($host, 'bitbucket')) {
            return "https://{$host}/{$path}/commits/{$hash}";
        }

        return "https://{$host}/{$path}/commit/{$hash}";
    }

    /**
     * @return array{0: string, 1: string}|null [host, path] — path has no leading/trailing
     *                                          slash and no trailing .git. Credentials embedded in an https URL (`user@host`
     *                                          or `user:pass@host`) are read by parse_url() into their own components and
     *                                          never touched here, so they cannot reach the templated URL even by accident.
     */
    private function parseRemote(string $url): ?array
    {
        if ($url === '') {
            return null;
        }

        if (str_contains($url, '://')) {
            $parts = parse_url($url);
            $host = $parts['host'] ?? null;
            $path = $parts['path'] ?? '';
        } elseif (preg_match('#^(?:[^/@]+@)?([^/:]+):(.+)$#', $url, $m)) {
            // scp-like syntax: [user@]host:path — e.g. git@github.com:owner/repo.git
            $host = $m[1];
            $path = $m[2];
        } else {
            return null;
        }

        if (! is_string($host) || $host === '') {
            return null;
        }

        $path = trim((string) $path, '/');
        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }

        return $path === '' ? null : [$host, $path];
    }

    /**
     * @return array{0: string|null, 1: bool} content (or null if this side has none — a
     *                                        missing parent on the file's first commit, or any other read failure) and
     *                                        whether it was truncated.
     */
    private function readBlob(string $rev, string $cwd): array
    {
        $result = $this->run(['git', 'show', $rev], $cwd);

        if ($result === null || $result['exitCode'] !== 0) {
            return [null, false];
        }

        return [$result['stdout'], $result['truncated']];
    }

    private function relativePath(string $absoluteFilePath, string $projectRoot): string
    {
        $root = rtrim($projectRoot, '/');

        return ltrim(substr($absoluteFilePath, strlen($root)), '/');
    }

    /**
     * @param  list<string>  $cmd
     * @return array{stdout: string, stderr: string, exitCode: int, truncated: bool}|null
     */
    private function run(array $cmd, string $cwd): ?array
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
            if (strlen($stdout) + strlen($chunk) > self::MAX_OUTPUT_BYTES) {
                $stdout .= substr($chunk, 0, self::MAX_OUTPUT_BYTES - strlen($stdout));
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

    /**
     * @return array{hash: string, shortHash: string, authorName: string, authorEmail: string, date: string, subject: string, diff: string, truncated: bool}
     */
    private function parse(string $stdout, bool $truncated): array
    {
        [$header, $rest] = array_pad(explode("\n", $stdout, 2), 2, '');
        [$hash, $authorName, $authorEmail, $date, $subject] = array_pad(explode("\x1f", $header), 5, '');

        $diff = ltrim($rest, "\n");
        if ($truncated) {
            $diff .= "\n[diff truncated — exceeded ".number_format(self::MAX_OUTPUT_BYTES).' bytes]';
        }

        return [
            'hash' => $hash,
            'shortHash' => substr($hash, 0, 7),
            'authorName' => $authorName,
            'authorEmail' => $authorEmail,
            'date' => $date,
            'subject' => $subject,
            'diff' => $diff,
            'truncated' => $truncated,
        ];
    }
}
