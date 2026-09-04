<?php

namespace App\Services;

/**
 * The control: a collaborator outside Actions/, which must stay a plain service.
 */
class OrderPricing
{
    public function quote()
    {
        return 0;
    }
}
