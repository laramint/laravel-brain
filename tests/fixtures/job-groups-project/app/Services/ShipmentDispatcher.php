<?php

namespace App\Services;

use App\Jobs\ChargeOrder;
use App\Jobs\NotifyWarehouse;
use App\Jobs\ReindexOrder;
use App\Jobs\ShipOrder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Every shape a chain or a batch is written in, one method each.
 *
 * Kept apart from the laravel-project fixture on purpose: several tests there assert node and
 * edge counts for the whole project, and four more jobs would move all of them.
 */
class ShipmentDispatcher
{
    public function chainThroughTheFacade(): void
    {
        Bus::chain([new ChargeOrder, new NotifyWarehouse]);
    }

    public function batchInsideATransaction(): void
    {
        DB::transaction(function () {
            Bus::batch([new ReindexOrder, NotifyWarehouse::class]);
        });
    }

    public function chainOnAPendingDispatch(): void
    {
        dispatch(new ChargeOrder)->chain([new NotifyWarehouse]);
    }

    public function chainOnTheJobItself(): void
    {
        ShipOrder::withChain([new NotifyWarehouse])->dispatch();
    }

    public function chainWithAnEntryNobodyCanRead(object $job): void
    {
        Bus::chain([$job, new NotifyWarehouse]);
    }

    public function compensatesAfterARollback(): void
    {
        try {
            DB::beginTransaction();
            Bus::dispatch(new ChargeOrder);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            dispatch(new NotifyWarehouse);
        }
    }

    public function retriesTheSameJobAfterARollback(): void
    {
        try {
            DB::beginTransaction();
            Bus::dispatch(new ReindexOrder);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            dispatch(new ReindexOrder);
        }
    }

    public function aDomainMethodThatHappensToBeCalledChain(object $pipeline): void
    {
        $pipeline->chain([new ChargeOrder, new ShipOrder]);
    }

    public function twoChainsInOneMethod(): void
    {
        Bus::chain([new ChargeOrder, new ShipOrder]);
        Bus::chain([new ReindexOrder, new NotifyWarehouse]);
    }
}
