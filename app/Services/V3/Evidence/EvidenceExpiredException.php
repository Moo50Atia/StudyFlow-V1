<?php

namespace App\Services\V3\Evidence;

use Exception;

class EvidenceExpiredException extends Exception
{
    public function __construct(string $message = 'Evidence export expired.')
    {
        parent::__construct($message, 410);
    }
}
