<?php

declare(strict_types=1);

namespace BarkaPay\Resources;

use BarkaPay\ApiClient;
use BarkaPay\Exception\ApiException;
use BarkaPay\Exception\ConfigurationException;
use BarkaPay\Exception\NetworkException;
use BarkaPay\Util\Coerce;

final class Operators
{
    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * Real-time availability per operator and service for the country:
     * `['operational' => bool, 'services' => [ISO-3 => [operator =>
     * ['payment' => ['status' => 'operational'|'degraded'|'down', …],
     * 'transfer' => […]]]]]`.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function availability(?string $country = null): array
    {
        return Coerce::arr($this->api->request('GET', ['operators', 'availability'], $country, expect: ''), 'data') ?? [];
    }

    /**
     * The authoritative list of operators for the country, with their
     * constraints: `['operators' => [ISO-3 => [operator => ['otp_required',
     * 'payment_instructions', 'pay_delay', 'min_amount', 'max_amount']]],
     * 'supported_countries' => […]]`.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function info(?string $country = null): array
    {
        return Coerce::arr($this->api->request('GET', ['operators', 'info'], $country, expect: ''), 'data') ?? [];
    }
}
