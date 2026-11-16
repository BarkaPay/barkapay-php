<?php

declare(strict_types=1);

namespace PayHub\Util;

use PayHub\Exception\ConfigurationException;

/**
 * Holds a credential OUTSIDE of any object property.
 *
 * A property — even a private one — is printed by `var_export()`, by an
 * `(array)` cast and by every dumper built on it (`dd()` / `dump()`, debuggers,
 * error pages); `__debugInfo()` only covers `var_dump()` and `print_r()`. The
 * value therefore lives in a static map keyed by this (property-less) object:
 * none of those tools can reach it, and it is freed with the object.
 *
 * @internal
 */
final class Secret
{
    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $values = null;

    public function __construct(
        #[\SensitiveParameter]
        string $value,
    ) {
        self::$values ??= new \WeakMap();
        self::$values[$this] = $value;
    }

    /**
     * @throws ConfigurationException when the value is gone (the object was deep-cloned)
     */
    public function reveal(): string
    {
        return self::$values[$this] ?? throw new ConfigurationException(
            'The credentials of this client are no longer available — build a new Client.',
        );
    }

    /**
     * Serialising a client is an explicit request to carry its credentials
     * along (a queued job, a cache entry): the payload contains the secret, so
     * it must be protected like the secret itself.
     *
     * @return array{value: string}
     */
    public function __serialize(): array
    {
        return ['value' => $this->reveal()];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $value = $data['value'] ?? null;
        if (!\is_string($value)) {
            throw new ConfigurationException('Cannot restore the client credentials: invalid serialized data.');
        }

        self::$values ??= new \WeakMap();
        self::$values[$this] = $value;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }
}
