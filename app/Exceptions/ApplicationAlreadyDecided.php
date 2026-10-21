<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Someone else approved, rejected or reopened the application first (two staff deciding at the same time).
 */
class ApplicationAlreadyDecided extends RuntimeException
{
    public static function nowStatus(string $status): self
    {
        return new self("This application was already changed by someone else and is now \"{$status}\". Refresh the page to see it.");
    }
}
