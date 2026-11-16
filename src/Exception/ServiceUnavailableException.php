<?php

declare(strict_types=1);

namespace PayHub\Exception;

/**
 * 503 — the operator (`operator_unavailable`) or a platform dependency
 * (`service_unavailable`) is temporarily unavailable. Honour
 * {@see getRetryAfter()} when it is set.
 *
 * When it answers a payment or transfer creation, check {@see getPayment()} /
 * {@see getTransfer()} — or look the operation up by your `order.id`
 * (`list(['order_id' => …])`) — before sending it again: an attempt may
 * already be recorded.
 */
final class ServiceUnavailableException extends ApiException
{
}
