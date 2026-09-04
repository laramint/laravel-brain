<?php

namespace App\Http\Controllers;

use App\Services\ReportBuilder;
use Illuminate\Support\Facades\Cache;

class DashboardController
{
    public function __construct(
        private ReportBuilder $reports,
    ) {}

    public function show($id)
    {
        $summary = Cache::remember('dashboard:'.$id, 600, fn () => $this->reports->build($id));
        $theme = cache('dashboard.theme');

        if ($summary === null) {
            $summary = Cache::store('redis')->get('dashboard:fallback');
        }

        return [$summary, $theme];
    }

    public function refresh($id, $key)
    {
        Cache::tags(['dashboard', 'reports'])->forget('dashboard:summary');
        Cache::store('redis')->put('dashboard:built_at', time(), 60);
        cache(['dashboard.theme' => 'dark'], 30);
        Cache::forget($key);

        // Repeated on purpose: the same fact twice is still one row in the panel.
        Cache::tags(['dashboard', 'reports'])->forget('dashboard:summary');

        $lock = Cache::lock('dashboard:rebuild', 10);

        return $lock;
    }

    public function plain()
    {
        return $this->reports->build(1);
    }
}
