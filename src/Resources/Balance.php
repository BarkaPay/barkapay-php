<?php

declare(strict_types=1);

namespace BarkaPay\Resources;

use BarkaPay\ApiClient;
use BarkaPay\DTO\Balance as BalanceDto;
use BarkaPay\Exception\ApiException;
use BarkaPay\Exception\ConfigurationException;
use BarkaPay\Exception\NetworkException;
use BarkaPay\Util\Coerce;

final class Balance
{
    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * The merchant's wallet balance for the country (scope `balance:read`).
     * Throws {@see \BarkaPay\Exception\NotFoundException} when the merchant has
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
