<?php

namespace App\Services\Press\Data;

use RuntimeException;

/** A data file that cannot be read safely. The message is written for the person who uploaded it. */
class DataImportException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }
}
