<?php

use LaraMint\LaravelBrain\Analysis\SourceDirectories;

function globFixture(): string
{
    $root = sys_get_temp_dir().'/lb-glob-'.bin2hex(random_bytes(6));
    mkdir($root.'/alpha/src', 0o777, true);
    mkdir($root.'/beta/src', 0o777, true);
    file_put_contents($root.'/alpha/notadir.php', "<?php\n");

    return $root;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/lb-glob-*') ?: [] as $dir) {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

it('expands a wildcard into the directories that exist', function () {
    $root = globFixture();

    $found = SourceDirectories::globDirectories($root.'/*/src');
    sort($found);

    expect($found)->toBe([$root.'/alpha/src', $root.'/beta/src']);
});

it('returns directories only, never files', function () {
    $root = globFixture();

    expect(SourceDirectories::globDirectories($root.'/alpha/*'))->toBe([$root.'/alpha/src']);
});

it('returns an empty array when nothing matches', function () {
    expect(SourceDirectories::globDirectories(globFixture().'/nothing/*'))->toBe([]);
});

it('expands a brace pattern where the platform defines GLOB_BRACE', function () {
    // musl does not define it on PHP 8.4 and older, and PHP only started bundling its own glob in
    // 8.5 — so this capability is genuinely absent on Alpine. The guard is what keeps its absence
    // from being fatal; see the docblock on globDirectories().
    if (! defined('GLOB_BRACE')) {
        expect(SourceDirectories::globDirectories(globFixture().'/{alpha,beta}/src'))->toBe([]);

        return;
    }

    $root = globFixture();
    $found = SourceDirectories::globDirectories($root.'/{alpha,beta}/src');
    sort($found);

    expect($found)->toBe([$root.'/alpha/src', $root.'/beta/src']);
});

it('keeps every directory glob behind the one guarded helper', function () {
    // The regression guard for issue #139, and the one that works on any platform. Naming
    // GLOB_BRACE outside globDirectories() is fatal wherever the constant is absent, and it was
    // named at four separate sites before. A fifth would reintroduce the crash.
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src'));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $source = (string) file_get_contents($path);

        if (str_ends_with($path, 'SourceDirectories.php')) {
            continue; // the helper itself, where the guard lives
        }

        if (str_contains($source, 'GLOB_BRACE')) {
            $offenders[] = basename($path);
        }
    }

    expect($offenders)->toBe([]);
});
