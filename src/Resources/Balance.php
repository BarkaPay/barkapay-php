<?php

declare(strict_types=1);

namespace PayHub\Resources;

use PayHub\ApiClient;
use PayHub\DTO\Balance as BalanceDto;
use PayHub\Exception\ApiException;
use PayHub\Exception\ConfigurationException;
use PayHub\Exception\NetworkException;
use PayHub\Util\Coerce;

final class Balance
{
    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * The merchant's wallet balance for the country (scope `balance:read`).
     * Throws {@see \PayHub\Exception\NotFoundException} when the merchant has
     * no wallet in that country yet.
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function get(?string $country = null): BalanceDto
    {
        $env = $this->api->request('GET', ['balance'], $country, expect: '');

        return BalanceDto::fromArray(Coerce::arr($env, 'data') ?? []);
    }
}
