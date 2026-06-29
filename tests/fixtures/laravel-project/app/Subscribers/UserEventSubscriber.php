<?php

namespace App\Subscribers;

use App\Events\AccountDeleted;
use App\Events\PasswordReset;
use Illuminate\Events\Dispatcher;

class UserEventSubscriber
{
    public function subscribe(Dispatcher $events): array
    {
        $events->listen(
            PasswordReset::class,
            [self::class, 'handlePasswordReset'],
        );

        return [
            AccountDeleted::class => 'handleAccountDeleted',
        ];
    }

    public function handlePasswordReset(PasswordReset $event): void {}

    public function handleAccountDeleted(AccountDeleted $event): void {}
}
