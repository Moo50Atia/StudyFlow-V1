<?php

namespace App\Services\V3\WebPipeline;

use Exception;

class ConcurrentUpdateException extends Exception
{
    protected string $safeCode;

    public function __construct(string $message = 'Concurrent update lock acquisition failed', int $code = 409, string $safeCode = 'concurrent_update', ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->safeCode = $safeCode;
    }

    public function getSafeCode(): string
    {
        return $this->safeCode;
    }
}
