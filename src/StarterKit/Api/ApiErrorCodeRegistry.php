<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Api;

use InvalidArgumentException;
use LogicException;

final class ApiErrorCodeRegistry
{
    /**
     * @var array<string, array{code: string, http_status: int, description: string, retryable: bool}>
     */
    private array $definitions = [];

    /**
     * @param  iterable<array{code: string, http_status: int, description: string, retryable?: bool}>  $definitions
     */
    public function registerMany(iterable $definitions): self
    {
        foreach ($definitions as $definition) {
            $this->register(
                $definition['code'],
                $definition['http_status'],
                $definition['description'],
                $definition['retryable'] ?? false,
            );
        }

        return $this;
    }

    public function register(
        string $code,
        int $httpStatus,
        string $description,
        bool $retryable = false,
    ): self {
        $code = trim($code);
        $description = trim($description);

        if (! preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $code)) {
            throw new InvalidArgumentException(sprintf('API error code [%s] must use snake_case.', $code));
        }

        if ($httpStatus < 100 || $httpStatus > 599) {
            throw new InvalidArgumentException('API error-code status must be between 100 and 599.');
        }

        if ($description === '') {
            throw new InvalidArgumentException('API error-code description must not be empty.');
        }

        $definition = [
            'code' => $code,
            'http_status' => $httpStatus,
            'description' => $description,
            'retryable' => $retryable,
        ];

        if (isset($this->definitions[$code]) && $this->definitions[$code] !== $definition) {
            throw new LogicException(sprintf('API error code [%s] is already registered differently.', $code));
        }

        $this->definitions[$code] = $definition;

        return $this;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * @return list<array{code: string, http_status: int, description: string, retryable: bool}>
     */
    public function catalogue(): array
    {
        return array_values($this->definitions);
    }
}
