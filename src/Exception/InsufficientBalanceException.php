<?php

declare(strict_types=1);

namespace BarkaPay\Exception;

/**
 * 400 `insufficient_balance` — the wallet cannot cover the transfer (amount +
 * fees). The request was refused before any operator was involved.
 */
final class InsufficientBalanceException extends ApiException
{
}
