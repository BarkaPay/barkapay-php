<?php

declare(strict_types=1);

namespace BarkaPay\Exception;

/** 5xx — unexpected error on the API side (`internal_error`, `external_service_error`, gateway errors). */
final class ServerException extends ApiException
{
}
