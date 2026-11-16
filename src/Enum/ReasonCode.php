<?php

declare(strict_types=1);

namespace PayHub\Enum;

/**
 * Stable, machine-readable failure codes carried by `reasonCode` on payments,
 * transfers and webhook events. The catalogue only grows: DTOs expose the raw
 * string, use `ReasonCode::tryFrom($payment->reasonCode ?? '')` to branch on
 * the values this version knows.
 *
 * `status` is the verdict; the reason code is an indication. Decide whether
 * money moved on the status only, and use the code for retry hints and support
 * messages.
 */
enum ReasonCode: string
{
    case PayerInsufficientFunds = 'payer_insufficient_funds';
    case PayerRefused = 'payer_refused';
    case PayerUnreachable = 'payer_unreachable';
    case InvalidOtp = 'invalid_otp';
    case OtpExpired = 'otp_expired';
    case Expired = 'expired';
    case RecipientInvalid = 'recipient_invalid';
    case OperatorUnavailable = 'operator_unavailable';
    case OperatorTimeout = 'operator_timeout';
    case UnconfirmedAfterWindow = 'unconfirmed_after_window';
    case PaymentNotCompleted = 'payment_not_completed';
    case AmountOutOfBounds = 'amount_out_of_bounds';
    case Duplicate = 'duplicate';
    /** The provider's payout liquidity is short — the merchant balance is not in cause. */
    case ProviderFloatInsufficient = 'provider_float_insufficient';
    /** The failure happened on the barkapay side — the operator is not at fault. */
    case InternalError = 'internal_error';
    case Unknown = 'unknown';
}
