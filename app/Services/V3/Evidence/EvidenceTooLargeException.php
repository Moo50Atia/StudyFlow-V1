<?php

namespace App\Services\V3\Evidence;

use Exception;

class EvidenceTooLargeException extends Exception
{
    public function __construct(string $message = 'Evidence snapshot too large.')
    {
        parent::__construct($message, 413);
    }
}
