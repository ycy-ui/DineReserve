<?php

declare(strict_types=1);

namespace DineReserve;

use RuntimeException;

/**
 * A rule of the booking domain was violated. Carries a stable machine code,
 * an HTTP status and machine-readable details so the API layer stays thin.
 */
final class DomainException extends RuntimeException
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $details = [],
        private readonly int $status = 422
    ) {
        parent::__construct($message);
    }

    /** @return array<string,string> */
    public static function validation(array $errors, string $message = 'The request contains invalid fields.'): self
    {
        return new self('VALIDATION_ERROR', $message, ['errors' => $errors], 422);
    }

    public static function notFound(string $message): self
    {
        return new self('NOT_FOUND', $message, [], 404);
    }

    public static function tableTaken(int $tableId, array $alternatives = []): self
    {
        return new self(
            'TABLE_TAKEN',
            'The selected table has just been booked by someone else. Please choose another slot.',
            ['table_id' => $tableId, 'alternatives' => $alternatives],
            409
        );
    }

    public static function conflict(string $message, array $details = []): self
    {
        return new self('CONFLICT', $message, $details, 409);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return $this->details;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details,
            ],
        ];
    }
}
