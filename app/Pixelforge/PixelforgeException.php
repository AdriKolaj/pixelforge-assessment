<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class PixelforgeException extends RuntimeException
{
    private const RETRYABLE = [
        'rate_limit_exceeded',
        'concurrency_limit_exceeded',
        'overloaded',
        'connection_error',
        'server_error',
    ];

    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $code = $response->json('error.code');

        if (! is_string($code)) {
            $code = $response->serverError() ? 'server_error' : 'unexpected_response';
        }

        $retryAfter = $response->header('Retry-After');

        return new self(
            message: $response->json('error.message') ?? "Pixelforge returned HTTP {$response->status()}.",
            errorCode: $code,
            status: $response->status(),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    public static function connection(Throwable $previous): self
    {
        return new self('Could not reach Pixelforge.', 'connection_error', previous: $previous);
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE, true);
    }

    /**
     * Whether Pixelforge may have created the generation even though we got an
     * error back. Their documented 4xx/503 errors guarantee nothing was created,
     * but a timeout or an unexpected 5xx tells us nothing.
     */
    public function outcomeUnknown(): bool
    {
        return in_array($this->errorCode, ['connection_error', 'server_error'], true);
    }
}
