<?php

namespace App\Ai\Tools;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class RefundTool implements Tool
{
    public function description(): string
    {
        return 'Refund an order.';
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
