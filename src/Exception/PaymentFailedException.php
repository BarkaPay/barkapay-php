<?php

declare(strict_types=1);

namespace PayHub\Exception;

/**
 * 424 `payment_failed` — the payer or the operator definitively refused the
 * payment. The attempt is recorded on the API side: read it with
 * {@see getPayment()} (status `FAILED`, `reasonCode`, `providerReason`).
 *
 * The verdict is final for this attempt: never replay the same request
 * automatically — start a new payment once the cause is fixed.
 */
final class PaymentFailedException extends ApiException
{
}
