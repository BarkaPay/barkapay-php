<?php

declare(strict_types=1);

namespace PayHub\Exception;

/**
 * 429 — rate limit reached. The request was not processed; wait
 * {@see getRetryAfter()} seconds before retrying.
 */
final class RateLimitException extends ApiException
{
}
