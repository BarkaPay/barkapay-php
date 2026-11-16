<?php

declare(strict_types=1);

namespace BarkaPay\Enum;

/**
 * Status of a payment. The API may introduce new values: a status this
 * version does not know hydrates as `null` on `Payment::$status`, while
 * `Payment::$rawStatus` always carries the string sent by the API.
 */
enum PaymentStatus: string
{
    case Created = 'CREATED';
    case AwaitingOtp = 'AWAITING_OTP';
    case QueuedForProcessing = 'QUEUED_FOR_PROCESSING';
    case ProcessingOperator = 'PROCESSING_OPERATOR';
    case ProcessingSystem = 'PROCESSING_SYSTEM';
    case Successful = 'SUCCESSFUL';
    case Failed = 'FAILED';
    /** The payer never completed (OTP never confirmed, session expired) — distinct from a real refusal. */
    case Expired = 'EXPIRED';
    case ComplianceFlagged = 'COMPLIANCE_FLAGGED';
    case Canceled = 'CANCELED';

    /** No further transitions: SUCCESSFUL, FAILED, EXPIRED or CANCELED. */
    public function isFinal(): bool
    {
        return \in_array($this, [self::Successful, self::Failed, self::Expired, self::Canceled], true);
    }

    /** The money was collected. Decide on this — never on a reason code. */
    public function isSuccessful(): bool
    {
        return $this === self::Successful;
    }
}
