<?php

declare(strict_types=1);

namespace BarkaPay\Exception;

/**
 * 424 `transfer_failed` — the operator definitively refused the payout. The
 * attempt is recorded on the API side: read it with {@see getTransfer()}
 * (status `FAILED`, `reasonCode`, `providerReason`).
 *
 * The verdict is final for this attempt: never replay the same request
 * automatically.
 */
final class TransferFailedException extends ApiException
{
}
