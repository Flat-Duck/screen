<?php

namespace App\Exceptions;

use RuntimeException;

final class DeviceProofOfPossessionRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This device is already registered. Re-registering it requires the current device token.');
    }
}
