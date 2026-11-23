<?php

namespace App\Services;

final readonly class SemaphoreSendResult
{
    public function __construct(
        public bool $accepted,
        public bool $retryable,
        public string $errorCode,
        public string $errorMessage,
        public ?string $providerMessageId,
        public ?string $providerStatus,
        public ?int $retryAfterSeconds,
        public ?array $providerResponse,
    ) {}
}
