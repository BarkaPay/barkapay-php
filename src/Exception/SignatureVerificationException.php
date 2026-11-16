<?php

declare(strict_types=1);

namespace BarkaPay\Exception;

/** A webhook signature could not be verified (bad signature, stale, malformed). */
final class SignatureVerificationException extends \RuntimeException implements BarkaPayException
{
}
