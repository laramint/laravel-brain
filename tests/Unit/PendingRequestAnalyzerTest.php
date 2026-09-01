<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\PendingRequestAnalyzer;

/**
 * A throwaway project holding one class, so a declaration can be varied one keyword at a time.
 *
 * The realistic shape — a client class, a policy wrapper, a service that calls through both —
 * lives in tests/fixtures/http-client-class-project and is exercised end to end elsewhere. What
 * belongs here is the matrix of ways a return type can be written, and those are variations of a
 * single line: a fixture app per variant would say less and cost more.
 */
function analyzerProjectWith(string $php, string $className = 'Subject'): array
{
    $root = sys_get_temp_dir().'/brain-pending-request-'.uniqid();
    mkdir($root.'/app', 0o777, true);
    file_put_contents($root.'/app/'.$className.'.php', $php);

    try {
        return (new PendingRequestAnalyzer)->analyze($root);
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
}

/** One class in namespace App, with the Laravel imports a client file would have. */
function clientClass(string $body, string $className = 'Subject'): string
{
    return <<<PHP
        <?php

        namespace App;

        use Illuminate\\Http\\Client\\PendingRequest;
        use Illuminate\\Support\\Facades\\Http;

        class {$className}
        {
        {$body}
        }
        PHP;
}

describe('what counts as a declaration', function () {
    it('finds a method that declares it returns a pending request', function () {
        $builders = analyzerProjectWith(clientClass(<<<'PHP'
                public function api(): PendingRequest
                {
                    return Http::baseUrl('https://api.example.test');
                }
            PHP));

        expect($builders)->toHaveKey('api')
            ->and($builders['api']['base']['url'])->toBe('https://api.example.test');
    });

    it('ignores a method with the same name that returns something else', function () {
        // The signal is the declaration. A method called api() that hands back an array is not a
        // request builder, and no amount of it reading like one makes it one.
        $builders = analyzerProjectWith(clientClass(<<<'PHP'
                public function api(): array
                {
                    return [];
                }
            PHP));

        expect($builders)->toBe([]);
    });

    it('ignores a method with no return type at all', function () {
        $builders = analyzerProjectWith(clientClass(<<<'PHP'
                public function api()
                {
                    return Http::baseUrl('https://api.example.test');
                }
            PHP));

        expect($builders)->toBe([]);
    });

    it('reads through a nullable return type', function () {
        $builders = analyzerProjectWith(clientClass(<<<'PHP'
                public function api(): ?PendingRequest
                {
                    return Http::baseUrl('https://api.example.test');
                }
            PHP));

        expect($builders)->toHaveKey('api');
    });

    it('reads through a union return type', function () {
        $builders = analyzerProjectWith(clientClass(<<<'PHP'
                public function api(): PendingRequest|null
                {
                    return Http::baseUrl('https://api.example.test');
                }
            PHP));

        expect($builders)->toHaveKey('api');
    });

    it('accepts a declaration on an interface', function () {
        // Often the only place the type is written down, and the call site is entitled to it.
        $builders = analyzerProjectWith(<<<'PHP'
            <?php

            namespace App;

            use Illuminate\Http\Client\PendingRequest;

            interface Client
            {
                public function api(): PendingRequest;
            }
            PHP, 'Client');

        expect($builders)->toHaveKey('api')
            ->and($builders['api'])->toBe([]);
    });

    it('does not accept a class of the project\'s own that happens to be called PendingRequest', function () {
        // `App\Support\PendingRequest` is somebody else's class with a familiar name. Resolving the
        // written type, rather than matching the word, is what keeps it out.
        $builders = analyzerProjectWith(<<<'PHP'
            <?php

            namespace App;

            use App\Support\PendingRequest;

            class Subject
            {
                public function api(): PendingRequest
                {
                    return new PendingRequest;
                }
            }
            PHP);

        expect($builders)->toBe([]);
    });
});

describe('the settings a builder applies', function () {
    it('keeps the base URL, timeout and retry the builder declares', function () {
        $builders = analyzerProjectWith(clientClass(<<<'PHP'
                public function api(): PendingRequest
                {
                    return Http::baseUrl('https://api.example.test')->timeout(5)->retry(3, 100);
                }
            PHP));

        expect($builders['api']['timeout'])->toBe(5.0)
            ->and($builders['api']['retryTimes'])->toBe(3)
            ->and($builders['api']['base']['url'])->toBe('https://api.example.test');
    });

    it('reads a request parked in a variable before it is returned', function () {
        $builders = analyzerProjectWith(clientClass(<<<'PHP'
                public function api(): PendingRequest
                {
                    $request = Http::baseUrl('https://api.example.test')->timeout(9);

                    return $request;
                }
            PHP));

        expect($builders['api']['timeout'])->toBe(9.0);
    });

    it('reads the policy a wrapper applies to a request handed to it', function () {
        // `applyTo(PendingRequest $request): PendingRequest` — the chain roots at the parameter,
        // and what it applies there is what every caller of the wrapper gets.
        $builders = analyzerProjectWith(clientClass(<<<'PHP'
                public function applyTo(PendingRequest $request): PendingRequest
                {
                    return $request->retry(4, 250)->timeout(7);
                }
            PHP));

        expect($builders['applyTo']['retryTimes'])->toBe(4)
            ->and($builders['applyTo']['retrySleep'])->toBe(250)
            ->and($builders['applyTo']['timeout'])->toBe(7.0);
    });

    it('carries the settings of a builder that leans on another builder', function () {
        // The second pass is what makes this readable: on the first, `applyTo` is not yet known to
        // build requests, so the retry policy it applies is invisible to `api`.
        $builders = analyzerProjectWith(clientClass(<<<'PHP'
                public function applyTo(PendingRequest $request): PendingRequest
                {
                    return $request->retry(4, 250);
                }

                public function api(): PendingRequest
                {
                    return $this->applyTo(Http::baseUrl('https://api.example.test'))->timeout(5);
                }
            PHP));

        expect($builders['api']['retryTimes'])->toBe(4)
            ->and($builders['api']['timeout'])->toBe(5.0)
            ->and($builders['api']['base']['url'])->toBe('https://api.example.test');
    });

    it('reports a builder it cannot read with no settings rather than guessed ones', function () {
        $builders = analyzerProjectWith(clientClass(<<<'PHP'
                public function api(): PendingRequest
                {
                    return $this->makeSomethingWeCannotFollow();
                }
            PHP));

        expect($builders)->toHaveKey('api')
            ->and($builders['api'])->toBe([]);
    });

    it('drops the settings two same-named declarations disagree about', function () {
        // Matching is by name, so a call site could mean either. A host borrowed from the wrong one
        // would print as this call's own — worse than printing none.
        $root = sys_get_temp_dir().'/brain-pending-request-'.uniqid();
        mkdir($root.'/app', 0o777, true);
        file_put_contents($root.'/app/One.php', clientClass(<<<'PHP'
                public function api(): PendingRequest
                {
                    return Http::baseUrl('https://one.test')->timeout(5);
                }
            PHP, 'One'));
        file_put_contents($root.'/app/Two.php', clientClass(<<<'PHP'
                public function api(): PendingRequest
                {
                    return Http::baseUrl('https://two.test')->timeout(5);
                }
            PHP, 'Two'));

        try {
            $builders = (new PendingRequestAnalyzer)->analyze($root);
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }

        expect($builders)->toHaveKey('api')
            ->and($builders['api'])->not->toHaveKey('base')
            ->and($builders['api']['timeout'])->toBe(5.0);
    });
});

it('finds nothing in a project whose source directories do not exist', function () {
    $root = sys_get_temp_dir().'/brain-pending-request-empty-'.uniqid();
    mkdir($root, 0o777, true);

    try {
        expect((new PendingRequestAnalyzer)->analyze($root))->toBe([]);
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});
