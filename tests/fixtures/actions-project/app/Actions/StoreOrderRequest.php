<?php

namespace App\Actions;

/**
 * A Form Request filed under Actions/. Recognised by its rules() method before the
 * directory is ever consulted, and it keeps that kind.
 */
class StoreOrderRequest
{
    public function rules()
    {
        return [
            'sku' => 'required|string',
            'quantity' => 'required|integer|min:1',
        ];
    }
}
