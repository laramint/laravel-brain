<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\GitChurnAnalyzer;

const CHURN_MAX_BYTES = 20_000_000;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/brain-churn-'.uniqid();
    mkdir($this->root, 0o777, true);

    exec('git init -q '.escapeshellarg($this->root));
    exec('git -C '.escapeshellarg($this->root).' config user.email test@example.com');
    exec('git -C '.escapeshellarg($this->root).' config user.name "Test User"');
    exec('git -C '.escapeshellarg($this->root).' config commit.gpgsign false');

    $this->analyzer = new GitChurnAnalyzer;
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
});

/**
 * @param  string|null  $isoDate  sets both author and committer date for a deterministic history
 * @param  string|null  $authorName  overrides the repo-wide "Test User" default for this one commit
 */
function churnCommit(string $root, string $relativePath, string $content, string $message, ?string $isoDate = null, ?string $authorName = null): void
{
    $full = $root.'/'.$relativePath;
    if (! is_dir(dirname($full))) {
        mkdir(dirname($full), 0o777, true);
    }
    file_put_contents($full, $content);

    $env = '';
    if ($isoDate !== null) {
        $env .= 'GIT_AUTHOR_DATE='.escapeshellarg($isoDate).' GIT_COMMITTER_DATE='.escapeshellarg($isoDate).' ';
    }
    if ($authorName !== null) {
        $env .= 'GIT_AUTHOR_NAME='.escapeshellarg($authorName).' ';
    }

    exec('git -C '.escapeshellarg($root).' add '.escapeshellarg($relativePath));
    exec($env.'git -C '.escapeshellarg($root).' commit -q -m '.escapeshellarg($message));
}

it('counts commits per file and records the most recent date', function () {
    churnCommit($this->root, 'foo.php', "<?php\n1", 'Add foo');
    churnCommit($this->root, 'foo.php', "<?php\n2", 'Update foo');
    churnCommit($this->root, 'bar.php', "<?php\nbar", 'Add bar');

    $result = $this->analyzer->scan($this->root, '5 years ago', CHURN_MAX_BYTES);

    expect($result[$this->root.'/foo.php']['commitCount'])->toBe(2)
        ->and($result[$this->root.'/bar.php']['commitCount'])->toBe(1)
        ->and($result[$this->root.'/foo.php']['lastChangedAt'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($result[$this->root.'/foo.php']['lastAuthor'])->toBe('Test User');
});

it('records the author of the most recent commit, not an earlier one', function () {
    churnCommit($this->root, 'foo.php', "<?php\n1", 'Add foo', authorName: 'Alice');
    churnCommit($this->root, 'foo.php', "<?php\n2", 'Update foo', authorName: 'Bob');

    $result = $this->analyzer->scan($this->root, '5 years ago', CHURN_MAX_BYTES);

    expect($result[$this->root.'/foo.php']['lastAuthor'])->toBe('Bob');
});

it('excludes merge commits from the count', function () {
    churnCommit($this->root, 'shared.php', "<?php\nbase", 'Initial');

    exec('git -C '.escapeshellarg($this->root).' branch branch-a');
    exec('git -C '.escapeshellarg($this->root).' checkout -q branch-a');
    churnCommit($this->root, 'other.php', "<?php\na", 'On branch-a');

    exec('git -C '.escapeshellarg($this->root).' checkout -q -');
    churnCommit($this->root, 'shared.php', "<?php\nmain", 'On main');
    exec('git -C '.escapeshellarg($this->root).' merge --no-edit branch-a > /dev/null 2>&1');

    $result = $this->analyzer->scan($this->root, '5 years ago', CHURN_MAX_BYTES);

    // 3 real commits touched files (Initial, On branch-a, On main); the merge commit itself
    // (a clean auto-merge, no conflict) must not inflate any count beyond that.
    expect($result[$this->root.'/shared.php']['commitCount'])->toBe(2)
        ->and($result[$this->root.'/other.php']['commitCount'])->toBe(1);
});

it('excludes commits older than the since window', function () {
    churnCommit($this->root, 'old.php', "<?php\nold", 'Old commit', '2015-01-01T12:00:00');
    churnCommit($this->root, 'new.php', "<?php\nnew", 'Recent commit');

    $result = $this->analyzer->scan($this->root, '1 year ago', CHURN_MAX_BYTES);

    expect($result)->not->toHaveKey($this->root.'/old.php')
        ->and($result[$this->root.'/new.php']['commitCount'])->toBe(1);
});

it('returns an empty array for an untracked directory with no commits', function () {
    expect($this->analyzer->scan($this->root, '5 years ago', CHURN_MAX_BYTES))->toBe([]);
});

it('returns an empty array when the directory is not a git repository at all', function () {
    $bareDir = sys_get_temp_dir().'/brain-churn-not-repo-'.uniqid();
    mkdir($bareDir, 0o777, true);

    try {
        expect($this->analyzer->scan($bareDir, '5 years ago', CHURN_MAX_BYTES))->toBe([]);
    } finally {
        exec('rm -rf '.escapeshellarg($bareDir));
    }
});

it('keys results by the scanned root joined with git relative paths, not the outer repo root', function () {
    // The regression test for a real bug found during planning: without --relative, git
    // reports paths relative to the repository's TOP LEVEL regardless of cwd. Here the git
    // repo root is $this->root, but the "project" being scanned is a subdirectory of it — the
    // common monorepo layout. Without --relative, this would return a key rooted at
    // $this->root instead of $this->root.'/app', matching nothing GraphBuilder ever writes.
    mkdir($this->root.'/app', 0o777, true);
    churnCommit($this->root, 'app/Controller.php', "<?php\n1", 'Add controller');
    churnCommit($this->root, 'README.md', '# readme', 'Add readme outside the app dir');

    $result = $this->analyzer->scan($this->root.'/app', '5 years ago', CHURN_MAX_BYTES);

    expect($result)->toHaveKey($this->root.'/app/Controller.php')
        ->and($result)->not->toHaveKey($this->root.'/Controller.php')
        ->and($result)->not->toHaveKey($this->root.'/app/app/Controller.php');
});

it('truncates output beyond the byte cap without crashing', function () {
    for ($i = 0; $i < 50; $i++) {
        churnCommit($this->root, "file{$i}.php", "<?php\n{$i}", "Add file {$i}");
    }

    $result = $this->analyzer->scan($this->root, '5 years ago', 50);

    expect($result)->toBeArray();
});
