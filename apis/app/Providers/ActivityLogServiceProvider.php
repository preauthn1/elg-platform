<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\General\ContextKeyEnum;
use App\Models;
use App\Observers\ActivityObserver;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class ActivityLogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 綁定要監聽的模型，參照 config/activitylog-member-events.php
        Models\Member::observe(ActivityObserver::class);
        Models\Order::observe(ActivityObserver::class);
        Models\WithdrawalOrder::observe(ActivityObserver::class);
        Models\MemberWallet::observe(ActivityObserver::class);
        Models\EventV2UserJoin::observe(ActivityObserver::class);

        /**
         * 為活動日誌添加請求批號與日誌關聯
         */
        Activity::saving(function (Activity $activity) {
            $requestId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

            if ($requestId) {
                $activity->batch_uuid = $requestId;
            }
        });
    }
}
