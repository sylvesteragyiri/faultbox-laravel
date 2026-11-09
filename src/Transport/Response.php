<?php

namespace Faultbox\Laravel\Transport;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly ?int $retryAfter = null,
        public readonly ?string $error = null,
    ) {
    }

    public function accepted(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** 429, 503 and network failures are worth one more try; 4xx are not. */
    public function retryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status === 503;
    }

    /** The error code from Faultbox's JSON error envelope, if any. */
    public function errorCode(): ?string
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? ($decoded['error']['code'] ?? null) : null;
    }
}
