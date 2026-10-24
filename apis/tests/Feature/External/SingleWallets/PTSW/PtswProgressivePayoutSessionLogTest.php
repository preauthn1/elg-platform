<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use App\Enums\Transaction\TransactionEnum;
use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Models\TransactionLog;
use App\Vendors\Models\PtswBetRecord;
use App\Vendors\Models\PtswDealRecord;
use App\Vendors\PlayTechSW\BetRecordStatusEnum;
use PHPUnit\Framework\Attributes\Group;

/**
 * PTSW 同一 gameRoundCode 連續派彩的 transaction_log session marker 驗證。
 *
 * PT live casino 一個 round 可能收到多筆 pay.type=WIN 的 settlement callback
 * （split / 保險 / 多手 / 累進獎勵），舊邏輯下「派彩變大」會觸發
 * BaseController::changeBalance 推斷 type=29 SEAMLESS_WALLET_CHANGE，
 * 每筆都新建 transaction_log，產生大量零碎紀錄。
 *
 * 新設計（2026-05-19）下 transaction_logs.type 翻轉為「錢包 session 標記」，
 * changeBalance 內所有 in-game 行為一律歸 27 SETTLE，靠
 * TransactionLogCreateService 延用機制把整段 session 累積在同一筆 log。
 *
 * 本測試以實際 vendor 流量（11 筆 WIN settlement + 1 筆 gameRoundClose）
 * 驗證新設計：
 *  - PtswBetRecord 同一筆累計（bet 不變、win_lose 累加 11 次 pay.amount）
 *  - TransactionLog 全程只有 1 筆（type=27、point/balance 累計）
 *  - PtswDealRecord 每個 requestId 都產生一筆
 *  - 最終 gameRoundClose 推進 BR.status=SETTLE 並建立 TotalBetRecord
 *
 * php artisan test tests/Feature/External/SingleWallets/PTSW/PtswProgressivePayoutSessionLogTest.php --compact
 */
class PtswProgressivePayoutSessionLogTest extends PtswTestCase
{
    #[Group('rules/vendor-ptsw#R-16')]
    #[Group('rules/vendor-ptsw#R-17')]
    public function test_consecutive_payouts_accumulate_into_single_transaction_log(): void
    {
        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $gameRoundCode = 'gpas_uXzl9FBLtfBJB6xEXlCs#6a4d8796';
        $betAmount = Common::numAdd('0', '50.0', 4); // 對齊 BR DECIMAL(4) 格式

        // ── placeBet ──
        $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-bet-1',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-bet-1',
            'amount' => $betAmount,
            'transactionDate' => '2026-05-17T17:55:20.000',
        ])->assertStatus(200)->assertJsonMissing(['error']);

        // ── 11 筆 WIN settlement（取自實際 PTSW callback 流量） ──
        $payouts = [
            ['req' => '5a32d3c7-4191-4ee3-8430-5bbdbcac1e79', 'tc' => '25669118906', 'amount' => '7.77', 'time' => '2026-05-17 17:55:26.000'],
            ['req' => '5043d0c9-593e-4bc3-8b7a-f64f21c84041', 'tc' => '25669125757', 'amount' => '74',    'time' => '2026-05-17 17:55:35.000'],
            ['req' => 'e5eaeb55-b83e-4790-bd71-fa0a2bff5f69', 'tc' => '25669130477', 'amount' => '5.55',  'time' => '2026-05-17 17:55:42.000'],
            ['req' => '02391381-bf93-4288-b4d8-15eed59022ee', 'tc' => '25669138292', 'amount' => '22.2',  'time' => '2026-05-17 17:55:53.000'],
            ['req' => '297c3680-35f8-417e-a4e9-72574eefc769', 'tc' => '25669149568', 'amount' => '3.7',   'time' => '2026-05-17 17:56:09.000'],
            ['req' => '90f8e501-971b-48be-b05e-4674026753ca', 'tc' => '25669151426', 'amount' => '74',    'time' => '2026-05-17 17:56:12.000'],
            ['req' => '92c43ae7-60d6-49a3-886c-acd8cbabb4d8', 'tc' => '25669166303', 'amount' => '3.7',   'time' => '2026-05-17 17:56:32.000'],
            ['req' => 'f153251e-f555-4ef3-9b3e-5d2d65d3082f', 'tc' => '25669168687', 'amount' => '0.37',  'time' => '2026-05-17 17:56:36.000'],
            ['req' => '260f897d-f4bd-4b9d-ad15-a593385830ac', 'tc' => '25669170727', 'amount' => '0.37',  'time' => '2026-05-17 17:56:38.000'],
            ['req' => '8a54952a-1714-499d-8c17-cf1804fc158f', 'tc' => '25669173764', 'amount' => '18.5',  'time' => '2026-05-17 17:56:43.000'],
            ['req' => '5ac0b362-c552-47be-924c-2929563b54a5', 'tc' => '25669178946', 'amount' => '0.74',  'time' => '2026-05-17 17:56:50.000'],
        ];

        $expectedTotalWin = '0';
        foreach ($payouts as $payout) {
            $expectedTotalWin = Common::numAdd($expectedTotalWin, $payout['amount'], 4);

            $this->postJson("{$this->baseurl}/settlement", [
                'requestId' => $payout['req'],
                'username' => $this->username,
                'externalToken' => $this->token,
                'gameRoundCode' => $gameRoundCode,
                'gameCodeName' => 'ubal',
                'pay' => [
                    'type' => 'WIN',
                    'transactionDate' => $payout['time'],
                    'amount' => $payout['amount'],
                    'betType' => 'MAIN_BET',
                    'internalFundChanges' => [],
                    'transactionCode' => $payout['tc'],
                ],
            ])->assertStatus(200)->assertJsonMissing(['error']);
        }

        // ── 第 12 筆：gameRoundClose（無 pay） ──
        $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => 'b6ddba13-35ef-4ab4-9995-f21c04ed7c8d',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'gameRoundClose' => [
                'date' => '2026-05-17 17:56:53.000',
                'rngSoftwareId' => '1.0.61',
                'rngGeneratorId' => '1.0.3',
            ],
            'jackpot' => [
                'winAmount' => '0',
                'contributionAmount' => '0',
            ],
        ])->assertStatus(200)->assertJsonMissing(['error']);

        // ── Bet record：同一筆累計 ──
        $this->assertSame(1, PtswBetRecord::count(), '同 gameRoundCode 不應產生多筆 BR');
        $betRecord = PtswBetRecord::first();
        $this->assertSame($gameRoundCode, $betRecord->bet_id);
        $this->assertEquals($betAmount, $betRecord->bet, 'bet 不變（settlement 階段 dealRecord.bet_amount=0）');
        $expectedWinLose = Common::numSub($expectedTotalWin, $betAmount, 4); // 11 筆派彩累加 - 投注
        $this->assertEquals(
            $expectedWinLose,
            $betRecord->win_lose,
            '11 筆 WIN settlement 後 win_lose 應為派彩總和扣除投注',
        );
        $this->assertSame(BetRecordStatusEnum::SETTLE->value, $betRecord->status, 'gameRoundClose 推進到 SETTLE');

        // ── Deal record：每個 requestId 一筆，共 1 + 11 + 1 = 13 ──
        $this->assertSame(13, PtswDealRecord::count(), 'bet + 11 WIN + 1 gameRoundClose 共 13 筆 deal_record');

        // ── Transaction log：session marker，全段只有 1 筆 ──
        $logs = TransactionLog::where('member_id', $this->member->id)->get();
        $this->assertCount(
            1,
            $logs,
            '新設計下整段 session（bet + 11 派彩 + close）只應產生 1 筆 transaction_log',
        );
        $log = $logs->first();
        $this->assertSame(
            TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value,
            $log->type,
            'type 必為 27 SEAMLESS_WALLET_BET_SETTLE（session 標記）',
        );
        // point 累計：calcActualAmount 反轉系統慣例下，bet 記為正、派彩記為負，
        // 累計值為 +bet - 11 筆 WIN = -(BR.win_lose)
        $this->assertEquals(
            Common::numMul($expectedWinLose, '-1', 4),
            $log->point,
            'log.point 為 bet (+正) 與 11 筆 WIN (-負) 的淨累計',
        );
        // before_balance 保留 placeBet 時的初始值，delicate update 不覆寫
        $this->assertEquals($initialBalance, $log->before_balance);
        // balance 被每次 delicate 覆寫，最終 = 初始 + win_lose
        $this->assertEquals(
            Common::numAdd($initialBalance, $expectedWinLose, 4),
            $log->balance,
        );

        // ── 玩家錢包：與 log.balance 一致 ──
        $this->member->refresh();
        $this->assertEquals(
            Common::numAdd($initialBalance, $expectedWinLose, 4),
            $this->member->balance,
        );

        // ── TotalBetRecord：gameRoundClose 觸發建立 1 筆 ──
        $this->assertSame(1, TotalBetRecord::where('vendor_id', $this->vendor->id)->count());
    }
}
