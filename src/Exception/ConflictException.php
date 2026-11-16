<?php

declare(strict_types=1);

namespace PayHub\Exception;

/**
 * 409 — duplicate: an `order.id` already used with `order.unique: true`, or the
 * 60-second duplicate-transfer guard. Nothing new was created.
 */
final class ConflictException extends ApiException
{
}
