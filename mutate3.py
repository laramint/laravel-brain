import subprocess, shutil, os, sys

ROOT = os.path.dirname(os.path.abspath(__file__))

MUTATIONS = [
    # The switch is ignored outright at the detection point.
    ("FlowExtractor ignores the flag (setter is a no-op)",
     "src/Analysis/FlowExtractor.php",
     "        $this->cacheDetector = $enabled ? ($this->cacheDetector ?? new CacheOperationDetector) : null;",
     "        $this->cacheDetector ??= new CacheOperationDetector;",
     ["tests/Unit/FlowExtractorTest.php", "tests/Unit/CacheOperationGraphTest.php", "tests/Unit/CacheOperationsConfigTest.php"]),

    # Off filters the OUTPUT instead of skipping the WORK — the exact failure the brief calls out.
    ("off filters results instead of skipping detection",
     "src/Analysis/FlowExtractor.php",
     "    public function setCacheOperationsEnabled(bool $enabled): void\n    {\n        $this->cacheDetector = $enabled ? ($this->cacheDetector ?? new CacheOperationDetector) : null;\n    }",
     "    public function setCacheOperationsEnabled(bool $enabled): void\n    {\n        $this->cacheDetector ??= new CacheOperationDetector;\n    }",
     ["tests/Unit/CacheOperationGraphTest.php"]),

    ("GraphBuilder does not forward the flag to FlowExtractor",
     "src/Graph/GraphBuilder.php",
     "        $this->cacheOperationsEnabled = $enabled;\n        $this->flowExtractor->setCacheOperationsEnabled($enabled);",
     "        $this->cacheOperationsEnabled = $enabled;",
     ["tests/Unit/CacheOperationGraphTest.php"]),

    ("GraphBuilder runs the collection pass regardless of the flag",
     "src/Graph/GraphBuilder.php",
     "        if ($this->cacheOperationsEnabled) {", "        if (true) {",
     ["tests/Unit/CacheOperationGraphTest.php"]),

    ("the flag defaults to off",
     "src/Graph/GraphBuilder.php",
     "    private bool $cacheOperationsEnabled = true;", "    private bool $cacheOperationsEnabled = false;",
     ["tests/Unit/CacheOperationGraphTest.php"]),

    ("ProjectAnalyzer never reads the config",
     "src/Analysis/ProjectAnalyzer.php",
     "        $this->graphBuilder->setCacheOperationsEnabled(\n            (bool) config('laravel-brain.cache_operations.enabled', true),\n        );",
     "",
     ["tests/Unit/CacheOperationsConfigTest.php"]),

    ("the config key is misspelled on the read side",
     "src/Analysis/ProjectAnalyzer.php",
     "config('laravel-brain.cache_operations.enabled', true)",
     "config('laravel-brain.cacheOperations.enabled', true)",
     ["tests/Unit/CacheOperationsConfigTest.php"]),

    ("the config default is flipped to off",
     "src/Analysis/ProjectAnalyzer.php",
     "config('laravel-brain.cache_operations.enabled', true)",
     "config('laravel-brain.cache_operations.enabled', false)",
     ["tests/Unit/CacheOperationsConfigTest.php"]),

    ("a falsy config value is no longer cast to a bool",
     "src/Analysis/ProjectAnalyzer.php",
     "            (bool) config('laravel-brain.cache_operations.enabled', true),",
     "            config('laravel-brain.cache_operations.enabled', true) !== false,",
     ["tests/Unit/CacheOperationsConfigTest.php"]),

    ("the published config ships the switch turned off",
     "config/laravel-brain.php",
     "'enabled' => env('LARAVEL_BRAIN_CACHE_OPERATIONS_ENABLED', true),",
     "'enabled' => env('LARAVEL_BRAIN_CACHE_OPERATIONS_ENABLED', false),",
     ["tests/Unit/PublishedConfigTest.php"]),
]


def run(test_files):
    return subprocess.run(
        ["./vendor/bin/pest", "--compact", *test_files],
        cwd=ROOT, capture_output=True, text=True,
    ).returncode


failures = []
for name, src, old, new, test_files in MUTATIONS:
    path = os.path.join(ROOT, src)
    if not os.path.exists(path):
        print(f"SKIP (no such file): {name}")
        failures.append(name)
        continue
    backup = path + ".bak"
    shutil.copyfile(path, backup)
    text = open(path).read()
    if old not in text:
        print(f"SKIP (anchor not found): {name}")
        os.remove(backup)
        failures.append(name)
        continue
    open(path, "w").write(text.replace(old, new, 1))
    code = run(test_files)
    shutil.move(backup, path)
    print(f"{'KILLED ' if code != 0 else 'SURVIVED'}  {name}")
    if code == 0:
        failures.append(name)

print()
print("survivors:", failures if failures else "none")
sys.exit(1 if failures else 0)
