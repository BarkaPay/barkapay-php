<?php

declare(strict_types=1);

namespace PayHub\Resources;

use PayHub\ApiClient;
use PayHub\Exception\ApiException;
use PayHub\Exception\ConfigurationException;
use PayHub\Exception\NetworkException;
use PayHub\Util\Coerce;

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
        return Coerce::arr($this->api->request('GET', ['operators', 'availability'], $country), 'data') ?? [];
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
        return Coerce::arr($this->api->request('GET', ['operators', 'info'], $country), 'data') ?? [];
    }
}
