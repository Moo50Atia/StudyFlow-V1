<?php

namespace App\Services\V3\WebPipeline;

use Exception;

class CommandConflictException extends Exception
{
    protected string $safeCode;

    public function __construct(string $message = 'Command conflict', int $code = 409, string $safeCode = 'control_conflict', ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->safeCode = $safeCode;
    }

    public function getSafeCode(): string
    {
        return $this->safeCode;
    }
}
