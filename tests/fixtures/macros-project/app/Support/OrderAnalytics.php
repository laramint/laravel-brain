<?php

namespace App\Support;

/** Mixed into the query builder: every public method becomes a builder method. */
class OrderAnalytics
{
    public function withRevenue(): callable
    {
        return fn () => null;
    }

    public function withMargin(): callable
    {
        return fn () => null;
    }

    /** Not contributed: a caller cannot reach it. */
    protected function helper(): void {}

    /** Not contributed either — a mixin gives instance methods. */
    public static function build(): void {}

    public function __construct() {}
}
