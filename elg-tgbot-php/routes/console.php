<?php

use App\Console\Commands\GetBotInfoList;
use App\Console\Commands\GetNewMemberList;
use App\Console\Commands\UpdateTgApiWhiteList;
use Illuminate\Support\Facades\Schedule;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote')->hourly();

Schedule::command(UpdateTgApiWhiteList::class)->everyTenMinutes()
    ->sendOutputTo(env('SCHEDULE_COMMAND_OUTPUT', 'php://stderr')); // 在線上環境運行的command 必須加入這一行
Schedule::command(GetBotInfoList::class)->everyTenMinutes()
    ->sendOutputTo(env('SCHEDULE_COMMAND_OUTPUT', 'php://stderr'));
Schedule::command(GetNewMemberList::class)->everyMinute()
    ->sendOutputTo(env('SCHEDULE_COMMAND_OUTPUT', 'php://stderr'));
