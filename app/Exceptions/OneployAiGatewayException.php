<?php

namespace App\Exceptions;

use Exception;

class OneployAiGatewayException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }
}
