<?php

namespace NotificationChannels\Fcm\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class InvalidTokenFound
{
    use Dispatchable, InteractsWithSockets;

    public $token;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(string $token)
    {
        $this->token = $token;
    }
}
