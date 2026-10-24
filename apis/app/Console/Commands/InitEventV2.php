<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Models\EventV2;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Services\EventV2\AngpowPeriodResetService;
use App\Services\EventV2\AngpowService;
use App\Services\EventV2\EventV2Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InitEventV2 extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:init-event-v2 {--eventV2Id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'init-event-v2';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $eventV2Id = $this->option('eventV2Id');

        Log::info('Init event v2 ' . $eventV2Id);

        // 紅包（target/tickets#4234）：週期是每檔活動自己的 member_period，不是整個類型一起清，
        // 交給 AngpowPeriodResetService 只重置「上一期還停在 JOIN」的紀錄。其他類型維持下面原本的「全部 JOIN 重置」。
        if (EventV2::query()->whereKey($eventV2Id)->value('handler_class') === class_basename(AngpowService::class)) {
            $result = app(AngpowPeriodResetService::class)->run(eventV2Id: (int) $eventV2Id);
            Log::info('Init event v2 ' . $eventV2Id . ' angpow period reset done', $result);

            return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        // event_v2_detail_id 一定要 select 進來：initEventV2() 讀 $userJoin->eventV2Detail->is_recycle（88cce18650 起），
        // 少了它關聯是 null，每一筆都在那行丟例外被 catch、只留 log，命令卻回成功——2026-02-22～10-01 正式站所有
        // 類型重置都這樣靜默失敗（vplus33 卡住 193 筆 JOIN）。with() 一次載入，避免逐筆懶載入
        $userJoin = EventV2UserJoin::select([
            'event_v2_user_joins.id',
            'event_v2_user_joins.event_v2_detail_id',
            'event_v2_user_joins.member_id',
            'event_v2_user_joins.status',
            'event_v2_user_joins.created_at',
        ])
            ->with('eventV2Detail')
            ->leftJoin('event_v2_details', 'event_v2_details.id', '=', 'event_v2_user_joins.event_v2_detail_id')
            ->where('event_v2_details.event_v2_id', $eventV2Id)
            ->where('event_v2_user_joins.status', EventV2UserJoinStatusEnum::JOIN->value)
            ->get();

        Log::info('Init event v2 ' . $eventV2Id . ' count ' . $userJoin->count());

        foreach ($userJoin as $join) {
            try {
                DB::beginTransaction();

                // 重置活動
                $member = Member::find($join->member_id);
                $join->withdraw = 0;
                $join->status = EventV2UserJoinStatusEnum::SYSTEM_INIT->value;
                app(EventV2Service::class)->initEventV2($member, $join);

                DB::commit();
            } catch (\Exception $exception) {
                DB::rollBack();
                Log::error('Init event v2 ' . $eventV2Id . ' error : ' . $exception->getMessage());
            }
        }

        Log::info('Init event v2 ' . $eventV2Id . ' done');

        return self::SUCCESS;
    }
}
