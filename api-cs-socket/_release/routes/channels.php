<?php

/*
 * |--------------------------------------------------------------------------
 * | Broadcast Channels
 * |--------------------------------------------------------------------------
 * |
 * | Here you may register all of the event broadcasting channels that your
 * | application supports. The given channel authorization callbacks are
 * | used to check if an authenticated user can listen to the channel.
 * |
 */
use App\Message;
use App\Events\TriggerAlarm;
use App\Events\MessageSent;

Broadcast::channel('broadcasting/auth', function ($user, $id) {
    return false;
});

Broadcast::channel('room.{code}', function ($user, $code) {
    DB::table('access_log_20190810')->create([
        'content' => $code
    ]);
    return false;
    return (int) $user->id === (int) $id;
});
