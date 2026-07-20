<?php

namespace App\Notifications\Contracts;

/** Marker for account-security pushes that bypass social preferences and quiet hours. */
interface SecurityFcmNotification extends FcmNotification {}
