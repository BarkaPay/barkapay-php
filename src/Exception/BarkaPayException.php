<?php

declare(strict_types=1);

namespace BarkaPay\Exception;

/**
 * Marker interface implemented by every exception the SDK throws, so callers
 * can `catch (\BarkaPay\Exception\BarkaPayException $e)` to trap anything from the
 * SDK in one place.
 */
interface BarkaPayException extends \Throwable
{
}
