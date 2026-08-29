<?php

namespace App\Services\AppCheck;

enum AppCheckVerification: string
{
    case Valid = 'valid';
    case Missing = 'missing';
    case Invalid = 'invalid';
    case Unavailable = 'unavailable';
}
