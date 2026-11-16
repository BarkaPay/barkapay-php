<?php

declare(strict_types=1);

namespace BarkaPay\Enum;

/**
 * Status of a transfer. The API may introduce new values: a status this
 * version does not know hydrates as `null` on `Transfer::$status`, while
 * `Transfer::$rawStatus` always carries the string sent by the API.
 */
enum TransferStatus: string
{
    case Created = 'CREATED';
    case ProcessingOperator = 'PROCESSING_OPERATOR';
    case ProcessingSystem = 'PROCESSING_SYSTEM';
    case Successful = 'SUCCESSFUL';
    case Failed = 'FAILED';
    case ComplianceFlagged = 'COMPLIANCE_FLAGGED';
    case Refunded = 'REFUNDED';
    /** Legacy spelling of {@see self::Uncertain}, still present on old transfers. */
    case Unresolved = 'UNRESOLVED';
    /**
     * The operator's answer is unknown (timeout, ambiguous reply): the payout
     * may be executing. NOT final and NOT a failure — never re-send the
     * transfer; wait for the webhook or poll `transfers->get()`.
     */
    case Uncertain = 'UNCERTAIN';

    /** No further transitions: SUCCESSFUL, FAILED or REFUNDED. */
    public function isFinal(): bool
    {
        return \in_array($this, [self::Successful, self::Failed, self::Refunded], true);
    }

    /** The money was paid out. Decide on this — never on a reason code. */
    public function isSuccessful(): bool
    {
        return $this === self::Successful;
    }
}
