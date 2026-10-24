<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Models\EventV2;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CheckEventV2Schedule extends Command
{
    protected $signature = 'check:event_v2_schedule';

    protected $description = '檢查 event_v2 資料表的 schedule 欄位並執行對應任務';

    public function handle(): int
    {
        Log::info('Check event v2 schedule start');
        if (Schema::hasTable('event_v2')) {
            $events = Cache::remember(CacheKeys::eventV2Schedule(), CacheTtl::ONE_HOUR->value, function () {
                return EventV2::select(['id', 'schedule'])->whereNotNull('schedule')->get();
            });

            Log::info('Check event v2 schedule count ' . $events->count());
            foreach ($events as $event) {
                $corn = new CronExpression($event->schedule);
                Log::info('isDue :' . $corn->isDue());
                if ($corn->isDue()) {
                    Log::info('Init event v2 ' . $event->id);
                    Artisan::call('app:init-event-v2', [
                        '--eventV2Id' => $event->id,
                    ]);
                }
            }
        }

        return self::SUCCESS;
    }
}
