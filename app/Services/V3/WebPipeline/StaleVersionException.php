<?php

namespace App\Services\V3\WebPipeline;

use Exception;

class StaleVersionException extends Exception
{
    protected string $safeCode;

    public function __construct(string $message = 'Stale state version', int $code = 409, string $safeCode = 'stale_version', ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->safeCode = $safeCode;
    }

    public function getSafeCode(): string
    {
        return $this->safeCode;
    }
}
