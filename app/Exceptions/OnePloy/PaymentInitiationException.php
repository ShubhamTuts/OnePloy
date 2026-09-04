<?php

namespace App\Exceptions\OnePloy;

use Exception;

class PaymentInitiationException extends Exception
{
    public function __construct()
    {
        parent::__construct('The payment provider could not start checkout.');
    }
}
