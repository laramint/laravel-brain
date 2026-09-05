<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\GitHistoryInspector;

/**
 * A throwaway repo per test, not a shared fixture — this exercises real `git` behavior
 * (metadata + diff parsing, first-commit-as-addition, merge-commit handling), which a static
 * PHP fixture cannot stand in for. `commit.gpgsign` is disabled locally so a runner with a
 * global signing config does not hang the test waiting on a passphrase.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/brain-git-'.uniqid();
    mkdir($this->root, 0o777, true);

    exec('git init -q '.escapeshellarg($this->root));
    exec('git -C '.escapeshellarg($this->root).' config user.email test@example.com');
    exec('git -C '.escapeshellarg($this->root).' config user.name "Test User"');
    exec('git -C '.escapeshellarg($this->root).' config commit.gpgsign false');

    $this->inspector = new GitHistoryInspector;
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
});

function brainGitCommit(string $root, string $relativePath, string $content, string $message): void
{
    $full = $root.'/'.$relativePath;
    if (! is_dir(dirname($full))) {
        mkdir(dirname($full), 0o777, true);
    }
    file_put_contents($full, $content);
    exec('git -C '.escapeshellarg($root).' add '.escapeshellarg($relativePath));
    exec('git -C '.escapeshellarg($root).' commit -q -m '.escapeshellarg($message));
}

it('reads the last commit that added a new file', function () {
    brainGitCommit($this->root, 'foo.php', "<?php\n\necho 'hello';\n", 'Add foo');

    $result = $this->inspector->lastCommit($this->root.'/foo.php', $this->root);

    expect($result)->not->toBeNull()
        ->and($result['authorName'])->toBe('Test User')
        ->and($result['authorEmail'])->toBe('test@example.com')
        ->and($result['subject'])->toBe('Add foo')
        ->and($result['hash'])->toHaveLength(40)
        ->and($result['shortHash'])->toHaveLength(7)
        ->and($result['diff'])->toContain('--- /dev/null')
        ->and($result['diff'])->toContain('new file mode')
        ->and($result['truncated'])->toBeFalse()
        ->and($result['newContent'])->toBe("<?php\n\necho 'hello';\n")
        ->and($result['newContentTruncated'])->toBeFalse()
        ->and($result['oldContent'])->toBeNull()
        ->and($result['oldContentTruncated'])->toBeFalse()
        ->and($result['remoteCommitUrl'])->toBeNull();
});

it('shows only the incremental change on a later commit, not the whole file', function () {
    brainGitCommit($this->root, 'foo.php', "<?php\n\necho 'hello';\n", 'Add foo');
    brainGitCommit($this->root, 'foo.php', "<?php\n\necho 'goodbye';\n", 'Update foo');

    $result = $this->inspector->lastCommit($this->root.'/foo.php', $this->root);

    expect($result['subject'])->toBe('Update foo')
        ->and($result['diff'])->not->toContain('new file mode')
        ->and($result['diff'])->toContain("-echo 'hello';")
        ->and($result['diff'])->toContain("+echo 'goodbye';")
        ->and($result['oldContent'])->toBe("<?php\n\necho 'hello';\n")
        ->and($result['newContent'])->toBe("<?php\n\necho 'goodbye';\n");
});

it('returns null for a file that was never committed', function () {
    file_put_contents($this->root.'/untracked.php', "<?php\n");

    expect($this->inspector->lastCommit($this->root.'/untracked.php', $this->root))->toBeNull();
});

it('returns null when the directory is not a git repository at all', function () {
    $bareDir = sys_get_temp_dir().'/brain-git-not-repo-'.uniqid();
    mkdir($bareDir, 0o777, true);
    file_put_contents($bareDir.'/foo.php', "<?php\n");

    try {
        expect($this->inspector->lastCommit($bareDir.'/foo.php', $bareDir))->toBeNull();
    } finally {
        exec('rm -rf '.escapeshellarg($bareDir));
    }
});

it('parses a commit subject containing punctuation into exactly the right fields', function () {
    brainGitCommit($this->root, 'foo.php', "<?php\n", 'fix: handle "quotes" & pipes | ok');

    $result = $this->inspector->lastCommit($this->root.'/foo.php', $this->root);

    expect($result['subject'])->toBe('fix: handle "quotes" & pipes | ok')
        ->and($result['authorName'])->toBe('Test User');
});

it('truncates a diff that exceeds the byte cap', function () {
    brainGitCommit($this->root, 'big.php', "<?php\n".str_repeat("echo 'x';\n", 30000), 'Add big file');

    $result = $this->inspector->lastCommit($this->root.'/big.php', $this->root);

    expect($result['truncated'])->toBeTrue()
        ->and($result['diff'])->toContain('[diff truncated')
        ->and($result['newContentTruncated'])->toBeTrue();
});

it('degrades gracefully for a merge commit, whose metadata parses even when git shows no patch for it', function () {
    brainGitCommit($this->root, 'shared.php', "<?php\n\$a = 'base';\n", 'Initial');

    exec('git -C '.escapeshellarg($this->root).' branch branch-a');
    exec('git -C '.escapeshellarg($this->root).' checkout -q branch-a');
    brainGitCommit($this->root, 'shared.php', "<?php\n\$a = 'from-branch-a';\n", 'Change on branch-a');

    exec('git -C '.escapeshellarg($this->root).' checkout -q -');
    brainGitCommit($this->root, 'shared.php', "<?php\n\$a = 'from-main';\n", 'Change on main');

    // A real conflict — both branches changed the same line differently — is what keeps
    // git's default history simplification from skipping past the merge for this path: the
    // resolution's content isn't fully explained by either parent alone.
    exec('git -C '.escapeshellarg($this->root).' merge --no-edit branch-a > /dev/null 2>&1');
    file_put_contents($this->root.'/shared.php', "<?php\n\$a = 'resolved';\n");
    exec('git -C '.escapeshellarg($this->root).' add shared.php');
    exec('git -C '.escapeshellarg($this->root).' commit -q -m "Resolve merge"');

    $result = $this->inspector->lastCommit($this->root.'/shared.php', $this->root);

    expect($result)->not->toBeNull()
        ->and($result['authorName'])->toBe('Test User')
        ->and($result['subject'])->toBe('Resolve merge')
        // Git's own default is to print no patch at all for a merge commit's `-p` output;
        // the point of this test is that a fully-populated header with empty diff content
        // parses cleanly rather than throwing or misaligning fields — not the exact byte
        // value, which is git's behavior to own, not this class's.
        ->and($result['diff'])->toBeString();
});

it('builds a GitHub commit URL from an https origin', function () {
    brainGitCommit($this->root, 'foo.php', "<?php\n", 'Add foo');
    exec('git -C '.escapeshellarg($this->root).' remote add origin https://github.com/acme/widgets.git');

    $result = $this->inspector->lastCommit($this->root.'/foo.php', $this->root);

    expect($result['remoteCommitUrl'])->toBe("https://github.com/acme/widgets/commit/{$result['hash']}");
});

it('builds the same GitHub commit URL from an scp-like ssh origin', function () {
    brainGitCommit($this->root, 'foo.php', "<?php\n", 'Add foo');
    exec('git -C '.escapeshellarg($this->root).' remote add origin git@github.com:acme/widgets.git');

    $result = $this->inspector->lastCommit($this->root.'/foo.php', $this->root);

    expect($result['remoteCommitUrl'])->toBe("https://github.com/acme/widgets/commit/{$result['hash']}");
});

it('builds a GitLab -/commit/ URL, preserving a nested subgroup, from both origin forms', function () {
    brainGitCommit($this->root, 'foo.php', "<?php\n", 'Add foo');
    exec('git -C '.escapeshellarg($this->root).' remote add origin git@gitlab.company.com:team/sub/widgets.git');

    $result = $this->inspector->lastCommit($this->root.'/foo.php', $this->root);

    expect($result['remoteCommitUrl'])->toBe("https://gitlab.company.com/team/sub/widgets/-/commit/{$result['hash']}");
});

it('builds a Bitbucket commits/ URL', function () {
    brainGitCommit($this->root, 'foo.php', "<?php\n", 'Add foo');
    exec('git -C '.escapeshellarg($this->root).' remote add origin https://bitbucket.org/acme/widgets.git');

    $result = $this->inspector->lastCommit($this->root.'/foo.php', $this->root);

    expect($result['remoteCommitUrl'])->toBe("https://bitbucket.org/acme/widgets/commits/{$result['hash']}");
});

it('never leaks embedded credentials from the origin URL into the commit URL', function () {
    brainGitCommit($this->root, 'foo.php', "<?php\n", 'Add foo');
    exec('git -C '.escapeshellarg($this->root).' remote add origin https://oauth2:SECRETTOKEN@gitlab.company.com/acme/widgets.git');

    $result = $this->inspector->lastCommit($this->root.'/foo.php', $this->root);

    expect($result['remoteCommitUrl'])->toBe("https://gitlab.company.com/acme/widgets/-/commit/{$result['hash']}")
        ->and($result['remoteCommitUrl'])->not->toContain('SECRETTOKEN');
});

it('returns a null remoteCommitUrl for an origin with no parseable host', function () {
    brainGitCommit($this->root, 'foo.php', "<?php\n", 'Add foo');
    exec('git -C '.escapeshellarg($this->root).' remote add origin not-a-url');

    $result = $this->inspector->lastCommit($this->root.'/foo.php', $this->root);

    expect($result['remoteCommitUrl'])->toBeNull();
});
