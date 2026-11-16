<?php

declare(strict_types=1);

namespace BarkaPay\Enum;

/**
 * Status of a payment link. An unknown future value hydrates as `null` on
 * `PaymentLink::$status`; `PaymentLink::$rawStatus` keeps the API string.
 */
enum PaymentLinkStatus: string
{
    /** Payable (unless `expiresAt` is in the past — expiry is evaluated lazily). */
    case Active = 'active';
    /** Put on hold by the merchant; `paymentLinks->resume()` re-opens it. */
    case Paused = 'paused';
    /** Single-use link closed by its first successful payment. */
    case Paid = 'paid';
    case Expired = 'expired';
    case Canceled = 'canceled';
    /** Blocked by barkapay — contact support. */
    case AdminBlocked = 'admin_blocked';

    /** The hosted checkout currently accepts payments on this link. */
    public function isPayable(): bool
    {
        return $this === self::Active;
    }
}
