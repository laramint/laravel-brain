<?php

use LaraMint\LaravelBrain\Http\Controllers\BrainController;

it('accepts a file inside the project root', function () {
    $root = fixture('laravel-project');
    $file = realpath($root.'/routes/api.php');

    expect(BrainController::isWithinProjectRoot($file, $root))->toBeTrue();
});

it('accepts the file when the root is given without realpath already applied', function () {
    $root = fixture('laravel-project');
    $file = realpath($root.'/routes/api.php');

    expect(BrainController::isWithinProjectRoot($file, $root.'/./'))->toBeTrue();
});

it('rejects a file outside the project root, even one that happens to share its prefix', function () {
    $root = fixture('laravel-project');
    $sibling = fixture('laravel-project').'-evil-twin';

    // The sibling fixture need not exist on disk — isWithinProjectRoot is a pure string
    // comparison over already-resolved paths, so this proves the DIRECTORY_SEPARATOR
    // boundary check rather than relying on realpath() to fail closed for us.
    expect(BrainController::isWithinProjectRoot($sibling.'/config/database.php', $root))->toBeFalse();
});

it('rejects a path when the project root itself does not resolve', function () {
    expect(BrainController::isWithinProjectRoot('/etc/passwd', '/no/such/project'))->toBeFalse();
});

it('rejects the project root path itself, which is not "a file within" the root', function () {
    $root = fixture('laravel-project');

    expect(BrainController::isWithinProjectRoot($root, $root))->toBeFalse();
});
