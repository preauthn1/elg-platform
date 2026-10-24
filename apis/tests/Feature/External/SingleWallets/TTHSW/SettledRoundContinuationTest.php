<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\TTHSW;

use App\Enums\BetRecordStatusEnum;
use App\Models\TotalBetRecord;
use App\Vendors\Models\TTHSWBetRecord;
use App\Vendors\TTHSW\Enums\ErrorCodeEnum;
use App\Vendors\TTHSW\Enums\TransactionTypeEnum;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 商戶 info 開 ALLOW_AFTER_COMPLETE_ROUND 時，回合結束（CompleteRound）後同一局續送的交易照常處理，
 * 注單維持已結算、TBR 跟著更新；未開時照舊擋下（預設）。
 *
 * 主案例取自 CHOICE 買 Free game 的實際送法：扣款 → Win 0 結束回合 → 多筆 0 元扣款 → Free game 派彩。
 */
class SettledRoundContinuationTest extends TTHSWTestCase
{
    private string $round = 'tth-round-settled';

    private string $initialBalance = '1000.0000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallet->update(['balance' => $this->initialBalance]);

        $this->vendorInfo->info = array_merge($this->vendorInfo->info, ['ALLOW_AFTER_COMPLETE_ROUND' => true]);
        $this->vendorInfo->save();
    }

    #[TestDox('商戶未開 ALLOW_AFTER_COMPLETE_ROUND（預設）：回合結束後的扣款與派彩照舊擋下，不動錢包')]
    public function test_transactions_after_complete_round_are_rejected_by_default(): void
    {
        $info = $this->vendorInfo->info;
        unset($info['ALLOW_AFTER_COMPLETE_ROUND']);
        $this->vendorInfo->info = $info;
        $this->vendorInfo->save();

        $this->assertSuccess($this->postDeduct('tx-1', '50', '2026-10-16T05:55:58.000+00:00'));
        $this->assertSuccess($this->postWin('tx-close', '0', '2026-10-16T05:55:59.000+00:00', completeRound: true));

        $this->postDeduct('tx-spin', '10', '2026-10-16T05:56:10.000+00:00')
            ->assertJsonPath('code', ErrorCodeEnum::ERROR->value);
        $this->postWin('tx-old-win', '35', '2026-10-21T05:31:55.000+00:00', completeRound: true)
            ->assertJsonPath('code', ErrorCodeEnum::ERROR->value);

        $this->assertBalance('950.0000');
        $this->assertSame('50.0000', (string) $this->betRecord()->bet);
        $this->assertSame('0.0000', (string) $this->betRecord()->win);
    }

    #[TestDox('買 free game：結束回合後的 0 元扣款與派彩都收下，派彩入帳一次、注單維持已結算、TBR 更新')]
    public function test_free_game_after_complete_round_is_accepted(): void
    {
        $this->assertSuccess($this->postDeduct('tx-buy', '50', '2026-10-16T05:55:58.000+00:00'));
        $this->assertSuccess($this->postWin('tx-close', '0', '2026-10-16T05:55:59.000+00:00', completeRound: true));
        $this->assertSame(BetRecordStatusEnum::SETTLE, $this->betRecord()->status);

        foreach (['tx-spin-1', 'tx-spin-2', 'tx-spin-3'] as $i => $transactionId) {
            $this->assertSuccess($this->postDeduct($transactionId, '0', sprintf('2026-10-16T05:56:1%d.000+00:00', $i)));
            $this->assertSame(BetRecordStatusEnum::SETTLE, $this->betRecord()->status, '已結算的局不得改回進行中');
        }
        $this->assertBalance('950.0000');

        $this->assertSuccess($this->postWin('tx-fg-win', '120', '2026-10-16T05:57:30.000+00:00', completeRound: true));
        $this->assertBalance('1070.0000');

        $betRecord = $this->betRecord();
        $this->assertSame('50.0000', (string) $betRecord->bet);
        $this->assertSame('120.0000', (string) $betRecord->win);
        $this->assertSame('70.0000', (string) $betRecord->win_lose);
        $this->assertSame(BetRecordStatusEnum::SETTLE, $betRecord->status);
        $this->assertTrue(Carbon::parse('2026-10-16 05:57:30')->eq($betRecord->payout_time), 'payout_time 要是最後一筆派彩的時間');

        $totalBetRecord = $this->totalBetRecord($betRecord);
        $this->assertSame('50.0000', (string) $totalBetRecord->bet);
        $this->assertSame('70.0000', (string) $totalBetRecord->win_lose);
    }

    #[TestDox('結束回合後的有金額扣款：扣錢、注單維持已結算，TBR 立即反映新投注額')]
    public function test_credited_deduct_after_complete_round_syncs_total_bet_record(): void
    {
        $this->assertSuccess($this->postDeduct('tx-1', '100', '2026-10-16T06:00:00.000+00:00'));
        $this->assertSuccess($this->postWin('tx-w1', '30', '2026-10-16T06:00:01.000+00:00', completeRound: true));

        $this->assertSuccess($this->postDeduct('tx-2', '20', '2026-10-16T06:00:05.000+00:00'));
        $this->assertBalance('910.0000');

        $betRecord = $this->betRecord();
        $this->assertSame('120.0000', (string) $betRecord->bet);
        $this->assertSame(BetRecordStatusEnum::SETTLE, $betRecord->status);

        $totalBetRecord = $this->totalBetRecord($betRecord);
        $this->assertSame('120.0000', (string) $totalBetRecord->bet);
        $this->assertSame('-90.0000', (string) $totalBetRecord->win_lose);
    }

    #[TestDox('結束回合後同一筆扣款重送：照去重回應，不重複扣錢')]
    public function test_duplicate_deduct_after_complete_round_is_not_charged_twice(): void
    {
        $this->assertSuccess($this->postDeduct('tx-1', '100', '2026-10-16T06:00:00.000+00:00'));
        $this->assertSuccess($this->postWin('tx-w1', '0', '2026-10-16T06:00:01.000+00:00', completeRound: true));

        $this->assertSuccess($this->postDeduct('tx-2', '10', '2026-10-16T06:00:05.000+00:00'));
        $this->postDeduct('tx-2', '10', '2026-10-16T06:00:05.000+00:00');

        $this->assertBalance('890.0000');
        $this->assertSame('110.0000', (string) $this->betRecord()->bet);
    }

    #[TestDox('結束回合後的 Resettle（文件 2.7 範例）：派彩改為新總額')]
    public function test_resettle_after_complete_round_replaces_payout(): void
    {
        $this->assertSuccess($this->postDeduct('tx-1', '10', '2026-10-16T06:00:00.000+00:00'));
        $this->assertSuccess($this->postWin('tx-w1', '50', '2026-10-16T06:00:01.000+00:00', completeRound: true));
        $this->assertBalance('1040.0000');

        $this->assertSuccess($this->postWin('tx-rs', '100', '2026-10-16T06:01:00.000+00:00', completeRound: true, type: TransactionTypeEnum::RESETTLE));

        $this->assertBalance('1090.0000');
        $this->assertSame('100.0000', (string) $this->betRecord()->win);
    }

    private function postDeduct(string $transactionId, string $amount, string $transactionDate): \Illuminate\Testing\TestResponse
    {
        return $this->postSigned(self::API_TTHSW_DEDUCT, [
            'PlayerCode' => $this->playerCode,
            'ProviderCode' => $this->providerCode,
            'GameRoundId' => $this->round,
            'GameCode' => $this->game->game_type,
            'TransactionId' => $transactionId,
            'TransactionDate' => $transactionDate,
            'Currency' => $this->currency,
            'Amount' => $amount,
            'Type' => TransactionTypeEnum::BET->value,
        ])->assertStatus(200);
    }

    private function postWin(
        string $transactionId,
        string $amount,
        string $transactionDate,
        bool $completeRound,
        TransactionTypeEnum $type = TransactionTypeEnum::WIN,
    ): \Illuminate\Testing\TestResponse {
        return $this->postSigned('/external/api/sw/tthsw/win', [
            'PlayerCode' => $this->playerCode,
            'ProviderCode' => $this->providerCode,
            'GameRoundId' => $this->round,
            'GameCode' => $this->game->game_type,
            'TransactionId' => $transactionId,
            'TransactionDate' => $transactionDate,
            'Currency' => $this->currency,
            'Amount' => $amount,
            'Type' => $type->value,
            'RefundPreviousWin' => $type === TransactionTypeEnum::RESETTLE,
            'CreditToWallet' => true,
            'CompleteRound' => $completeRound,
        ])->assertStatus(200);
    }

    private function assertSuccess(\Illuminate\Testing\TestResponse $response): void
    {
        $this->assertSame(
            ErrorCodeEnum::SUCCESS->value,
            $response->json('code'),
            '回調失敗：' . (string) $response->json('message')
        );
    }

    private function assertBalance(string $expected): void
    {
        $this->assertSame($expected, (string) $this->wallet->refresh()->balance);
    }

    private function betRecord(): TTHSWBetRecord
    {
        return TTHSWBetRecord::where('bet_id', $this->round)->firstOrFail();
    }

    private function totalBetRecord(TTHSWBetRecord $betRecord): TotalBetRecord
    {
        return TotalBetRecord::where('vendor_id', $this->vendorId)
            ->where('bet_record_id', $betRecord->id)
            ->firstOrFail();
    }
}
