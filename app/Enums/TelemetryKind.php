<?php

namespace App\Enums;

enum TelemetryKind: string
{
    case Event = 'event';
    case Error = 'error';
    case FatalCrash = 'fatal_crash';
}
