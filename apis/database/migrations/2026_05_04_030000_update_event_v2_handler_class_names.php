<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** [oldHandlerClass => [handler_class, name]] */
    private array $updates = [
        'RegisterService' => ['RegisterService',              '註冊'],
        'DailyFreeService' => ['DailyFreeService',             '每日登入'],
        'WelcomeBonus50Service' => ['MonthlyDepositService',        '每月儲值'],
        'WelcomeBonus100Service' => ['MonthlyDeposit2Service',       '每月儲值2'],
        'SlotBonus10Service' => ['DepositService',               '不限次儲值'],
        'SlotBonus30Service' => ['DailyDepositService',          '每日儲值'],
        'DailyAchievementRewardService' => ['DailyDepositRangeService',   '每日區間儲值'],
        'WeeklyAchievementRewardService' => ['WeeklyDepositService',      '每周累積儲值'],
        'DailyDepositComboRewardService' => ['DailyDepositComboService',  '每日儲值組合'],
        'InviteFriendService' => ['InviteFriendService',          '邀請好友'],
        'DownlineCommissionService' => ['DownlineCommissionService',    '下線傭金'],
    ];

    public function up(): void
    {
        foreach ($this->updates as $old => [$newClass, $newName]) {
            DB::table('event_v2')
                ->where('handler_class', $old)
                ->update(['handler_class' => $newClass, 'name' => $newName]);
        }
    }

    public function down(): void
    {
        foreach ($this->updates as $old => [$newClass, $newName]) {
            DB::table('event_v2')
                ->where('handler_class', $newClass)
                ->update(['handler_class' => $old]);
        }
    }
};
