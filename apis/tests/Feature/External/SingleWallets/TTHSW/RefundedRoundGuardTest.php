<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\TTHSW;

use App\Enums\BetRecordStatusEnum;
use App\Vendors\Models\TTHSWBetRecord;
use App\Vendors\Models\TTHSWDealRecord;
use App\Vendors\TTHSW\Enums\ErrorCodeEnum;
use App\Vendors\TTHSW\Enums\TransactionTypeEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 整局退款（CANCEL_ROUND 把 bet 歸零、status 改 REFUND）之後，任何還會動錢包的請求都必須被擋
 * （target/op_tickets#3099）。
 *
 * 這道守衛原本只掛在 processWin，四條退款分支與 complete 都沒有；checkBetRecordNotSettled() 又只擋
 * SETTLE 不認 REFUND，於是已退光的注單還能再被扣一次（bet 被扣成負數）或再派一次彩。
 *
 * ⚠️ 其中 ADJUST 與 RELEASE_RESERVE 兩條屬**行為變更**（原本照做，現在回 403），六站生產零發生、
 * 亦無 TTHSW 三方文件佐證「TTH 在整局退款後會不會送合法的 ADJUST 校正」。這兩案在此釘住現行決策，
 * 待向 TTH 確認後若語意有變，改動看得到代價。
 */
class RefundedRoundGuardTest extends TTHSWTestCase
{
    private string $round = 'tth-round-3099-guard';

    private string $transactionDate = '2026-09-08T10:00:00.000+00:00';

    private string $initialBalance = '1000.0000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallet->update(['balance' => $this->initialBalance]);
    }

    #[TestDox('整局退款後送 ADJUST：回 403、不得調整派彩')]
    #[Group('rules/vendor-tthsw#R-32')]
    public function test_adjust_is_rejected_on_refunded_round(): void
    {
        $this->placeBet('tth-bet-adjust', '100');
        $this->cancelRoundAndAssertRefunded('tth-cancel-round-adjust');

        $this->postRefund([
            'TransactionId' => 'tth-adjust-1',
            'Amount' => '50',
            'Type' => TransactionTypeEnum::ADJUST->value,
        ])->assertStatus(200)->assertJsonPath('code', ErrorCodeEnum::GAME_ROUND_REFUNDED->value);

        $this->assertNothingChangedAfterRefund();
        $this->assertSame('0.0000', (string) $this->betRecord()->win, '已退光的局不得被調整派彩');
    }

    #[TestDox('整局退款後送 RELEASE_RESERVE：回 403、不得再退預留額')]
    #[Group('rules/vendor-tthsw#R-32')]
    public function test_release_reserve_is_rejected_on_refunded_round(): void
    {
        $this->reserve('tth-reserve-1', '100');
        $this->cancelRoundAndAssertRefunded('tth-cancel-round-reserve');

        $this->postRefund([
            'TransactionId' => 'tth-release-1',
            'Amount' => '0',
            'Type' => TransactionTypeEnum::RELEASE_RESERVE->value,
            'ReserveBetRoundingTolerance' => '0',
        ])->assertStatus(200)->assertJsonPath('code', ErrorCodeEnum::GAME_ROUND_REFUNDED->value);

        $this->assertNothingChangedAfterRefund();
    }

    #[TestDox('整局退款後換新 TransactionId 重送 CANCEL_ROUND：回 403、不得再退一次')]
    #[Group('rules/vendor-tthsw#R-27')]
    #[Group('rules/vendor-tthsw#R-32')]
    public function test_cancel_round_retry_with_new_transaction_id_is_rejected(): void
    {
        $this->placeBet('tth-bet-round-retry', '100');
        $this->cancelRoundAndAssertRefunded('tth-cancel-round-1');

        $this->postRefund([
            'TransactionId' => 'tth-cancel-round-2',
            'Amount' => '0',
            'Type' => TransactionTypeEnum::CANCEL_ROUND->value,
        ])->assertStatus(200)->assertJsonPath('code', ErrorCodeEnum::GAME_ROUND_REFUNDED->value);

        $this->assertNothingChangedAfterRefund();
        $this->assertSame(
            1,
            TTHSWDealRecord::where('game_round_id', $this->round)
                ->where('type', TransactionTypeEnum::CANCEL_ROUND->value)
                ->count(),
            '被擋下的重送不得再寫一筆整局取消紀錄'
        );
    }

    #[TestDox('整局退款後送 Complete 帶派彩：回 403、不得在已退光的局上再派一次彩')]
    #[Group('rules/vendor-tthsw#R-32')]
    public function test_complete_is_rejected_on_refunded_round(): void
    {
        $this->placeBet('tth-bet-complete', '100');
        $this->cancelRoundAndAssertRefunded('tth-cancel-round-complete');

        $this->postSigned(self::API_TTHSW_COMPLETE, [
            'PlayerCode' => $this->playerCode,
            'ProviderCode' => $this->providerCode,
            'GameRoundId' => $this->round,
            'GameCode' => $this->game->game_type,
            'TransactionId' => 'tth-complete-1',
            'TransactionDate' => $this->transactionDate,
            'Currency' => $this->currency,
            'TotalPayout' => '500',
            'CreditToWallet' => true,
            'Type' => TransactionTypeEnum::END_ROUND->value,
        ])->assertStatus(200)->assertJsonPath('code', ErrorCodeEnum::GAME_ROUND_REFUNDED->value);

        $this->assertNothingChangedAfterRefund();
        $this->assertSame(BetRecordStatusEnum::REFUND, $this->betRecord()->status, '注單不得被改成已結算');
    }

    /** 整局取消後：錢全退回、注單歸零並標記 REFUND */
    private function cancelRoundAndAssertRefunded(string $transactionId): void
    {
        $this->postRefund([
            'TransactionId' => $transactionId,
            'Amount' => '0',
            'Type' => TransactionTypeEnum::CANCEL_ROUND->value,
        ])->assertStatus(200)->assertJsonPath('code', ErrorCodeEnum::SUCCESS->value);

        $this->assertSame($this->initialBalance, (string) $this->wallet->refresh()->balance, '前置：整局取消須全額退回');
        $this->assertSame(BetRecordStatusEnum::REFUND, $this->betRecord()->status);
        $this->assertSame('0.0000', (string) $this->betRecord()->bet);
    }

    /** 被守衛擋下之後，錢包與注單金額都必須停在整局取消後的狀態 */
    private function assertNothingChangedAfterRefund(): void
    {
        $this->assertSame($this->initialBalance, (string) $this->wallet->refresh()->balance, '被擋下的請求不得動錢包');

        $betRecord = $this->betRecord();
        $this->assertSame('0.0000', (string) $betRecord->bet, '注單投注額不得被再扣一次');
        $this->assertSame('0.0000', (string) $betRecord->win_lose);
    }

    private function placeBet(string $transactionId, string $amount): void
    {
        $this->postDeduct($transactionId, $amount, TransactionTypeEnum::BET->value);
    }

    private function reserve(string $transactionId, string $amount): void
    {
        $this->postDeduct($transactionId, $amount, TransactionTypeEnum::RESERVE->value);
    }

    private function postDeduct(string $transactionId, string $amount, int $type): void
    {
        $response = $this->postSigned(self::API_TTHSW_DEDUCT, [
            'PlayerCode' => $this->playerCode,
            'ProviderCode' => $this->providerCode,
            'GameRoundId' => $this->round,
            'GameCode' => $this->game->game_type,
            'TransactionId' => $transactionId,
            'TransactionDate' => $this->transactionDate,
            'Currency' => $this->currency,
            'Amount' => $amount,
            'Type' => $type,
        ])->assertStatus(200);

        // TTHSW 的失敗一律回 907，訊息才看得出原因
        $this->assertSame(
            ErrorCodeEnum::SUCCESS->value,
            $response->json('code'),
            '前置扣款失敗：' . (string) $response->json('message')
        );
    }

    private function postRefund(array $overrides): \Illuminate\Testing\TestResponse
    {
        return $this->postSigned(self::API_TTHSW_REFUND, array_merge([
            'PlayerCode' => $this->playerCode,
            'ProviderCode' => $this->providerCode,
            'GameRoundId' => $this->round,
            'GameCode' => $this->game->game_type,
            'TransactionDate' => $this->transactionDate,
            'Currency' => $this->currency,
        ], $overrides));
    }

    private function betRecord(): TTHSWBetRecord
    {
        return TTHSWBetRecord::where('bet_id', $this->round)->firstOrFail();
    }
}
