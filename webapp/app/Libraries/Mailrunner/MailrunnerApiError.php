<?php

namespace App\Libraries\Mailrunner;

use RuntimeException;

/** Error answer of the MailRunner API with its code (e.g. USERNAME_EXISTS, INVALID_NETWORK). */
class MailrunnerApiError extends RuntimeException
{
    public function __construct(public readonly string $apiCode, string $message)
    {
        parent::__construct($message);
    }
}
