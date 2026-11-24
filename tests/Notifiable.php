<?php

namespace Benwilkins\FCM\Tests;

/**
 * A notifiable whose FCM route is whatever the test gives it,
 * like a User returning its device_tokens array.
 */
class Notifiable
{
    use \Illuminate\Notifications\Notifiable;

    /** @var mixed */
    public $fcmRoute;

    /** @var int */
    public $routeCalls = 0;

    public function __construct($fcmRoute = null)
    {
        $this->fcmRoute = $fcmRoute;
    }

    public function routeNotificationForFcm()
    {
        $this->routeCalls++;

        return $this->fcmRoute;
    }
}
