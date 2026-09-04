<?php

namespace App\Ai\Tools;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SearchOrdersTool implements Tool
{
    public function description(): string
    {
        return 'Search the customer orders by e-mail address.';
    }

    public function handle(Request $request): string
    {
        return '';
    }

    public function schema($schema): array
    {
        return [];
    }
}
