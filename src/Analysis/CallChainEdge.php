<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * Represents a single directed hop discovered during deep tracing.
 *
 * e.g.  OrderController::store  →  OrderService::createOrder  (type: service)
 *       OrderService::createOrder → OrderRepository::create   (type: repository)
 *       OrderRepository::create  → Order                      (type: model)
 *       OrderService::createOrder → SendOrderConfirmationJob  (type: job)
 */
class CallChainEdge
{
    public function __construct(
        public string $callerFqcn,
        public string $callerMethod,
        public string $calleeFqcn,
        public string $calleeMethod,
        /** 'service' | 'repository' | 'model' | 'job' | 'event' | 'action' | 'view' | 'mail' | 'notification' | 'enum' | 'interface' | 'trait' | 'abstract_class' */
        public string $type,
        /** 'public' | 'protected' | 'private' */
        public string $visibility = 'public',
        /** The call was made inside a database transaction. */
        public bool $inTransaction = false,
        /** The call is on the compensation path — it runs only after a rollback. */
        public bool $inRollback = false,
        /** Which span, as `Fqcn::method#n`. Nodes sharing it were inside one transaction. */
        public ?string $transactionId = null,
    ) {}
}
