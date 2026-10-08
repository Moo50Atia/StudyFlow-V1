<?php

namespace App\Services\V3\Evidence;

use Exception;

class EvidenceUnavailableException extends Exception
{
    public function __construct(string $message = 'Evidence unavailable.')
    {
        parent::__construct($message, 503);
    }
}
