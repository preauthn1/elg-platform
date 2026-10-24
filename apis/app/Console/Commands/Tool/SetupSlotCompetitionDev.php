<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use App\Enums\Event\EventActionIdEnum;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventSlotCompetition;
use App\Models\Prize;
use App\Models\PrizeContent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 手動建立老虎機爭霸賽的測試用期數（測試環境專用）。
 *
 * 停用所有現存的老虎機爭霸賽活動，再依範本活動複製出一期「涵蓋現在」的新活動，
 * 讓排程能立即開始統計，用於在測試環境驗排行榜／投注額累積／領獎流程。
 *
 * ## 為什麼需要手動建
 *
 * 期數正常是由 stored procedure `ADMIN_slot_competition_automatic_increase` 自動遞增，
 * 但它有兩道門檻，測試環境通常都不滿足：
 *
 *   1. 需要 MySQL EVENT `Event_Slot_Competition_Automatic_Increase` 驅動（SP 註解裡有建立語法），
 *      測試環境往往沒有建這支 EVENT，SP 於是從未被呼叫。
 *   2. 游標要求 `events.status = 1 AND events.auto_increment = 1`，
 *      測試環境的舊活動常常一邊啟用、一邊沒開自動遞增，剛好各缺一半。
 *
 * 更關鍵的是該 SP 是「從最後一期往後推一期」，一次呼叫只新增一期。舊期數若已停在
 * 一兩年前，要推到今天得跑上百次（日排程就是上百天），實務上追不回來——所以測試環境
 * 要驗這個活動，只能像本命令這樣直接建一期涵蓋現在的期數。
 *
 * ## 統計鏈路（建完之後多久看得到數字）
 *
 *   1. MySQL EVENT `Event_Job_Vendor_Hour_Record`（每 5 分）→ `vendor_hour_records`
 *   2. 舊後台 artisan `summary_event`（每 15 分）→ `event_processes.point`（套用遊戲商／遊戲篩選）
 *   3. MySQL EVENT `Event_Slot_Competition_Job`（每 15 分）→ `SP_calc_slot_competition`
 *      → `event_slot_competition_records`（bet_amount ＋ ranking）
 *
 * 所以下注後最多兩個 tick、約 30 分鐘內會出現在排行榜。查看要用
 * `event/slot-competition/leader-board`；`ranking`（我的排名）有 `draw_time <= NOW()`
 * 條件，得等開獎後才看得到該期。
 *
 * ## 挑範本時的兩個坑
 *
 *   - `vendor_id`：由 --vendor-id 決定、預設 0（不限），刻意不沿用範本。鎖定某遊戲商時
 *     測試帳號押在別家的流水一毛都不會算進來，舊期常見鎖定 Pegasus 就是這個坑。
 *   - `expire_hour`：舊期有 0 的，會讓獎項在 `draw_time` 一過就被判定「已逾期」而領不到，
 *     故本命令預設補成 96 小時，可用 --expire-hour 覆蓋。
 *
 * ## 用法
 *
 *   php artisan dev:setup-slot-competition                    # dry-run，先看要停用哪些、要建什麼
 *   php artisan dev:setup-slot-competition --execute          # 實際寫入
 *   php artisan dev:setup-slot-competition --start='2026-08-16 16:00:00' --days=7 --execute
 *
 * 注意重跑會把上一次建的那期也一併停用，再開一期新的——這是刻意的（重置成乾淨的測試期數）。
 * 想沿用上次建好的設定當範本，把 --template-event / --template-detail 指到那次的 id。
 */
class SetupSlotCompetitionDev extends Command
{
    protected $signature = 'dev:setup-slot-competition
        {--execute : 實際寫入（預設 dry-run）}
        {--template-event=479 : 範本活動 id}
        {--template-detail=3019 : 範本期數 event_details.id}
        {--start= : 期數開始時間 Y-m-d H:i:s（UTC），預設本週日 16:00}
        {--days=7 : 期數天數}
        {--vendor-id=0 : 限定遊戲商，0 = 不限（不沿用範本，預設一律不限）}
        {--expire-hour=96 : 獎項領取期限（小時）}
        {--force : 跳過連線保護}';

    protected $description = '手動建立老虎機爭霸賽測試用期數（停用現存活動並依範本開一期涵蓋現在的）';

    /**
     * 允許執行的 DB name（測試環境）。
     *
     * 這支會停用「所有」老虎機爭霸賽活動，誤連到白標等於把正式活動關掉，故預設只放行測試庫。
     */
    private const string EXPECTED_DATABASE = 'xsgcasino';

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();

        if ($database !== self::EXPECTED_DATABASE && !$this->option('force')) {
            $this->error(sprintf(
                '連線指向 DB "%s"，非預期的 "%s"。這支會停用所有老虎機爭霸賽活動，拒絕執行；確定要跑請加 --force。',
                $database,
                self::EXPECTED_DATABASE
            ));

            return self::FAILURE;
        }

        $templateEvent = Event::find((int) $this->option('template-event'));
        $templateDetail = EventDetail::find((int) $this->option('template-detail'));

        if ($templateEvent === null || $templateDetail === null) {
            $this->error('找不到範本活動或範本期數。');

            return self::FAILURE;
        }

        $start = $this->option('start') !== null
            ? Carbon::parse((string) $this->option('start'))
            : now()->startOfWeek(Carbon::SUNDAY)->setTime(16, 0);
        $end = $start->copy()->addDays((int) $this->option('days'))->subSecond();
        $draw = $end->copy()->addDay()->setTime(6, 15);

        $staleEventIds = EventDetail::query()
            ->where('action_id', EventActionIdEnum::SLOT_MACHINE_COMPETITION->value)
            ->distinct()
            ->pluck('event_id');

        $vendorId = (int) $this->option('vendor-id');

        $templatePrize = Prize::where('event_id', $templateEvent->id)->where('status', 1)->first();
        $prizeContentCount = $templatePrize === null
            ? 0
            : PrizeContent::where('prize_id', $templatePrize->id)->count();

        $this->table(['項目', '值'], [
            ['DB', $database],
            ['範本活動', sprintf('%d「%s」agent_id=%d', $templateEvent->id, $templateEvent->title, $templateEvent->agent_id)],
            ['範本期數', sprintf('%d（game_type_id=%d, join_event=%d, rate=%s）',
                $templateDetail->id,
                (int) $templateDetail->game_type_id,
                (int) $templateDetail->game_join_event_used,
                $templateDetail->rate,
            )],
            ['vendor_id', $vendorId === 0
                ? '0（不限遊戲商）'
                : sprintf('%d（僅此遊戲商的流水會計入；範本原值 %d）', $vendorId, (int) $templateDetail->vendor_id)],
            ['將停用的活動', $staleEventIds->implode(', ')],
            ['新期數 start_time', $start->toDateTimeString()],
            ['新期數 end_time', $end->toDateTimeString()],
            ['新期數 draw_time', $draw->toDateTimeString()],
            ['expire_hour', (string) (int) $this->option('expire-hour')],
            ['獎項', $templatePrize === null ? '（範本無啟用獎項）' : sprintf('複製 prize %d ＋ %d 筆 prize_contents', $templatePrize->id, $prizeContentCount)],
            ['現在（DB now）', now()->toDateTimeString()],
        ]);

        if ($vendorId > 0) {
            $this->warn(sprintf(
                '⚠️ 已鎖定 vendor_id=%d，只有該遊戲商的流水會算進來；測試帳號押在別家會完全不計。要不限請省略 --vendor-id。',
                $vendorId
            ));
        }

        if ($start->gt(now()) || $end->lt(now())) {
            $this->warn('⚠️ 新期數不涵蓋現在，排程不會統計；請用 --start 調整。');
        }

        if (!$this->option('execute')) {
            $this->info('dry-run 結束，未寫入。確認無誤後加 --execute。');

            return self::SUCCESS;
        }

        $result = DB::transaction(function () use ($templateEvent, $templateDetail, $templatePrize, $staleEventIds, $start, $end, $draw, $vendorId) {
            $disabled = Event::whereIn('id', $staleEventIds)->update(['status' => 0]);

            $event = $templateEvent->replicate();
            $event->fill([
                'start_time' => $start->toDateTimeString(),
                'end_time' => $start->copy()->addYear()->toDateTimeString(),
                'status' => 1,
                'auto_increment' => 1,
            ]);
            $event->save();

            if ($templatePrize !== null) {
                $prize = $templatePrize->replicate();
                $prize->fill([
                    'event_id' => $event->id,
                    'draw_date' => $draw->toDateTimeString(),
                    'expire_date' => $draw->copy()->addDays(30)->toDateTimeString(),
                    'status' => 1,
                ]);
                $prize->save();

                PrizeContent::where('prize_id', $templatePrize->id)
                    ->get()
                    ->each(function (PrizeContent $content) use ($prize): void {
                        $copy = $content->replicate();
                        $copy->prize_id = $prize->id;
                        $copy->save();
                    });
            }

            $detail = $templateDetail->replicate();
            $detail->fill([
                'event_id' => $event->id,
                'vendor_id' => $vendorId,
                'start_time' => $start->toDateTimeString(),
                'end_time' => $end->toDateTimeString(),
                'draw_time' => $draw->toDateTimeString(),
                'expire_hour' => (int) $this->option('expire-hour'),
                'status' => 1,
            ]);
            $detail->save();

            $competition = EventSlotCompetition::create([
                'event_id' => $event->id,
                'event_detail_id' => $detail->id,
                'no' => 1,
                'draw_time' => $draw->toDateTimeString(),
                'status' => 0,
                'admin' => 0,
            ]);

            return compact('disabled', 'event', 'detail', 'competition');
        });

        $this->info(sprintf('已停用舊活動 %d 筆。', $result['disabled']));
        $this->info(sprintf(
            '新活動 event_id=%d、event_detail_id=%d、event_slot_competitions.id=%d（no=1）。',
            $result['event']->id,
            $result['detail']->id,
            $result['competition']->id,
        ));

        return self::SUCCESS;
    }
}
