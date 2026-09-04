<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\FacadeAnalyzer;
use LaraMint\LaravelBrain\Analysis\FacadeRecord;
use LaraMint\LaravelBrain\Parser\PhpFileParser;

function removeBenchmarkCorpus(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($dir);
}

it('generates an ordinary facade and one whose own file never mentions Facade', function () {
    $root = sys_get_temp_dir().'/brain-bench-facades-'.uniqid();

    $cmd = sprintf(
        '%s %s %s 1.0',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(dirname(__DIR__, 2).'/benchmark/generate-corpus.php'),
        escapeshellarg($root),
    );
    exec($cmd.' 2>/dev/null', $output, $code);
    expect($code)->toBe(0);

    $reporting = (string) file_get_contents($root.'/app/Support/Reporting.php');
    expect(str_contains(str_replace('Facades\\', '', $reporting), 'Facade'))->toBeFalse();

    expect((string) file_get_contents($root.'/app/Http/Controllers/Controller000.php'))
        ->toContain('\\App\\Facades\\Catalog::handle0');
    expect((string) file_get_contents($root.'/app/Http/Controllers/Controller001.php'))
        ->toContain('\\App\\Support\\Reporting::handle0');

    $appFiles = 0;
    $frameworkFacadeImports = 0;
    $bareFacadeHits = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS),
    );
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $appFiles++;
        $code = (string) file_get_contents($file->getPathname());
        if (str_contains($code, 'Illuminate\\Support\\Facades\\')) {
            $frameworkFacadeImports++;
        }
        if (str_contains(str_replace('Facades\\', '', $code), 'Facade')) {
            $bareFacadeHits++;
        }
    }

    expect($frameworkFacadeImports)->toBeGreaterThan((int) ($appFiles * 0.9));
    expect($bareFacadeHits)->toBeLessThan(10);

    $before = PhpFileParser::$parseCount;
    $registry = (new FacadeAnalyzer)->analyze($root);
    $parses = PhpFileParser::$parseCount - $before;

    expect($registry->get('App\Facades\Catalog'))
        ->toBeInstanceOf(FacadeRecord::class)
        ->accessor->toBe('App\Services\Service000')
        ->concreteFqcn->toBe('App\Services\Service000');

    expect($registry->get('App\Support\Reporting'))
        ->toBeInstanceOf(FacadeRecord::class)
        ->accessor->toBe('App\Services\Service001')
        ->concreteFqcn->toBe('App\Services\Service001');

    expect($registry->get('App\Support\Facades\Base'))->toBeNull();
    expect($parses)->toBeLessThan(10);

    removeBenchmarkCorpus($root);
});
