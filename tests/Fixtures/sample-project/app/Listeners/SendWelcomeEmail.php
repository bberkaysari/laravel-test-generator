<?php

namespace App\Listeners;

use App\Events\UserCreated;
use App\Mail\WelcomeMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendWelcomeEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public $connection = 'redis';
    public $queue = 'emails';
    public $delay = 60;

    /**
     * Handle the event.
     */
    public function handle(UserCreated $event): void
    {
        Mail::to($event->user->email)->send(new WelcomeMail($event->user));
    }

    /**
     * Determine whether the listener should be queued.
     */
    public function shouldQueue(UserCreated $event): bool
    {
        return $event->user->email_verified_at !== null;
    }
}
