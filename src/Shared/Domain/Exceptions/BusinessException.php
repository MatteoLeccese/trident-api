<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Exceptions;

/**
 * A business rule failure. Use it directly for cases with no behaviour of their
 * own; extend it when the case deserves a named type.
 */
class BusinessException extends DomainException
{
    private readonly string $errorCode;

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        string $errorCode,
        string $message,
        private readonly int $status = 422,
        private readonly ?array $data = null,
    ) {
        $this->errorCode = self::assertSnakeCase($errorCode);

        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function data(): ?array
    {
        return $this->data;
    }
}
