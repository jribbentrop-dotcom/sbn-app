<?php

namespace App\Services\Payments;

/**
 * A normalized payment webhook event.
 *
 * Each provider maps its own event names onto our two canonical types so the
 * webhook handler stays provider-agnostic.
 */
class PaymentEvent
{
    public const PURCHASE_COMPLETED = 'purchase.completed';
    public const PURCHASE_REFUNDED  = 'purchase.refunded';
    public const UNHANDLED          = 'unhandled';

    public function __construct(
        public readonly string $type,             // one of the constants above
        public readonly ?int $ourOrderId = null,  // our sbn_orders.id (from custom_data)
        public readonly ?string $providerOrderId = null,
        public readonly ?string $email = null,
        public readonly array $raw = [],          // full decoded payload
    ) {}

    public function isHandled(): bool
    {
        return $this->type !== self::UNHANDLED;
    }
}
