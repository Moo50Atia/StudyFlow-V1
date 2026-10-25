<?php

namespace App\Services\V3\LocalWorker\Protocol;

use RuntimeException;

class LocalWorkerProtocolException extends RuntimeException
{
    public string $protocolCode;
    public string $errorCode;
    public int $statusCode;

    public function __construct(string $message, string $protocolCode = 'contract_invalid', int $statusCode = 422)
    {
        parent::__construct($message, $statusCode);
        $this->protocolCode = $protocolCode;
        $this->errorCode = $protocolCode;
        $this->statusCode = $statusCode;
    }

    public function getProtocolCode(): string
    {
        return $this->protocolCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
