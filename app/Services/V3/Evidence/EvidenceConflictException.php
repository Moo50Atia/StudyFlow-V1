<?php

namespace App\Services\V3\Evidence;

use Exception;

class EvidenceConflictException extends Exception
{
    public function __construct(string $message = 'Evidence conflict.')
    {
        parent::__construct($message, 409);
    }
}
