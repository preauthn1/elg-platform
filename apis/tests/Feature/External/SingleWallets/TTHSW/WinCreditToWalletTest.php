<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\TTHSW;

use App\Enums\BetRecordStatusEnum;
use App\Vendors\Models\TTHSWBetRecord;
use App\Vendors\TTHSW\Enums\ErrorCodeEnum;
use App\Vendors\TTHSW\Enums\TransactionTypeEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Win 是否入帳只看 CreditToWallet（文件 2.6：Always follow CreditToWallet indicator），
 * CompleteRound 只決定注單是否結算；一局可有多筆 Win，派彩要累加（target/op_tickets#3626）。
 *
 * 各案例取自正式站實際收到的回調組合。
 */
class WinCreditToWalletTest extends TTHSWTestCase
{
    private string $round = 'tth-round-3626';

    private string $transactionDate = '2026-10-18T07:48:46.000+00:00';

    private string $initialBalance = '1000.0000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallet->update(['balance' => $this->initialBalance]);
    }

    #[TestDox('一局兩注：第一筆 Win 入帳但未結束回合、第二筆 Win 0 結束回合，派彩累加且只加一次')]
    #[Group('rules/vendor-tthsw#R-24')]
    #[Group('rules/vendor-tthsw#R-25')]
    public function test_multi_bet_round_credits_each_win_and_accumulates(): void
    {
        $this->postDeduct('tx-1', '450');
        $this->postDeduct('tx-2', '100');

        $this->postWin('tx-1', '877.5', creditToWallet: true, completeRound: false);
        $this->assertBalance('1327.5000');
        $this->assertNotSame(BetRecordStatusEnum::SETTLE, $this->betRecord()->status, '回合還沒結束不得結算');

        $this->postWin('tx-2', '0', creditToWallet: true, completeRound: true);
        $this->assertBalance('1327.5000');

        $betRecord = $this->betRecord();
        $this->assertSame('550.0000', (string) $betRecord->bet);
        $this->assertSame('877.5000', (string) $betRecord->win, '第二筆 Win 0 不得蓋掉第一筆派彩');
        $this->assertSame('327.5000', (string) $betRecord->win_lose);
        $this->assertSame(BetRecordStatusEnum::SETTLE, $betRecord->status);
    }

    #[TestDox('單注單 Win（CreditToWallet、CompleteRound 皆 true）：行為與修正前相同')]
    #[Group('rules/vendor-tthsw#R-24')]
    #[Group('rules/vendor-tthsw#R-25')]
    public function test_single_win_credits_and_settles(): void
    {
        $this->postDeduct('tx-1', '100');
        $this->postWin('tx-1', '250', creditToWallet: true, completeRound: true);

        $this->assertBalance('1150.0000');
        $this->assertSame('250.0000', (string) $this->betRecord()->win);
        $this->assertSame(BetRecordStatusEnum::SETTLE, $this->betRecord()->status);
    }

    #[TestDox('Win 皆不入帳、最後 Complete 帶總派彩入帳：只加一次總額')]
    #[Group('rules/vendor-tthsw#R-26')]
    #[Group('rules/vendor-tthsw#R-34')]
    public function test_uncredited_wins_then_complete_credits_total_once(): void
    {
        $this->postDeduct('tx-1', '100');
        $this->postWin('tx-w1', '30', creditToWallet: false, completeRound: false);
        $this->postWin('tx-w2', '20', creditToWallet: false, completeRound: false);
        $this->assertBalance('900.0000');

        $this->postSigned(self::API_TTHSW_COMPLETE, [
            'PlayerCode' => $this->playerCode,
            'ProviderCode' => $this->providerCode,
            'GameRoundId' => $this->round,
            'GameCode' => $this->game->game_type,
            'TransactionId' => 'tx-complete',
            'TransactionDate' => $this->transactionDate,
            'Currency' => $this->currency,
            'TotalPayout' => '50',
            'CreditToWallet' => true,
            'Type' => TransactionTypeEnum::END_ROUND->value,
        ])->assertStatus(200)->assertJsonPath('code', ErrorCodeEnum::SUCCESS->value);

        $this->assertBalance('950.0000');
        $this->assertSame('50.0000', (string) $this->betRecord()->win);
        $this->assertSame(BetRecordStatusEnum::SETTLE, $this->betRecord()->status);
    }

    #[TestDox('Win 入帳、Complete 不入帳且總派彩 0（PRGM 實際組合）：錢包加一次，注單派彩維持 Win 的金額')]
    #[Group('rules/vendor-tthsw#R-35')]
    public function test_credited_win_then_uncredited_complete_keeps_payout(): void
    {
        $this->postDeduct('tx-1', '2');
        $this->postWin('tx-w1', '15.6', creditToWallet: true, completeRound: false);
        $this->assertBalance('1013.6000');

        $this->postSigned(self::API_TTHSW_COMPLETE, [
            'PlayerCode' => $this->playerCode,
            'ProviderCode' => $this->providerCode,
            'GameRoundId' => $this->round,
            'GameCode' => $this->game->game_type,
            'TransactionId' => 'tx-complete',
            'TransactionDate' => $this->transactionDate,
            'Currency' => $this->currency,
            'TotalPayout' => '0',
            'CreditToWallet' => false,
            'Type' => TransactionTypeEnum::END_ROUND->value,
        ])->assertStatus(200)->assertJsonPath('code', ErrorCodeEnum::SUCCESS->value);

        $this->assertBalance('1013.6000');

        $betRecord = $this->betRecord();
        $this->assertSame('15.6000', (string) $betRecord->win, '不入帳的 Complete 不得蓋掉已入帳的派彩');
        $this->assertSame('13.6000', (string) $betRecord->win_lose);
        $this->assertSame(BetRecordStatusEnum::SETTLE, $betRecord->status);
    }

    #[TestDox('Win 不入帳但結束回合（CreditToWallet false、CompleteRound true）：不動錢包、注單結算')]
    #[Group('rules/vendor-tthsw#R-26')]
    public function test_uncredited_win_completing_round_does_not_touch_wallet(): void
    {
        $this->postDeduct('tx-1', '100');
        $this->postWin('tx-1', '0', creditToWallet: false, completeRound: true);

        $this->assertBalance('900.0000');
        $this->assertSame(BetRecordStatusEnum::SETTLE, $this->betRecord()->status);
    }

    #[TestDox('同一筆 Win 重送：被去重擋下，不會加兩次錢')]
    #[Group('rules/vendor-tthsw#R-11')]
    public function test_duplicate_win_is_not_credited_twice(): void
    {
        $this->postDeduct('tx-1', '450');
        $this->postDeduct('tx-2', '100');

        $this->postWin('tx-1', '877.5', creditToWallet: true, completeRound: false);
        $this->postSigned('/external/api/sw/tthsw/win', $this->winPayload('tx-1', '877.5', true, false))
            ->assertStatus(200);

        $this->assertBalance('1327.5000');
        $this->assertSame('877.5000', (string) $this->betRecord()->win);
    }

    private function postDeduct(string $transactionId, string $amount): void
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
            'Type' => TransactionTypeEnum::BET->value,
        ])->assertStatus(200);

        $this->assertSame(
            ErrorCodeEnum::SUCCESS->value,
            $response->json('code'),
            '前置扣款失敗：' . (string) $response->json('message')
        );
    }

    private function postWin(string $transactionId, string $amount, bool $creditToWallet, bool $completeRound): void
    {
        $response = $this->postSigned('/external/api/sw/tthsw/win', $this->winPayload($transactionId, $amount, $creditToWallet, $completeRound))
            ->assertStatus(200);

        $this->assertSame(
            ErrorCodeEnum::SUCCESS->value,
            $response->json('code'),
            'Win 失敗：' . (string) $response->json('message')
        );
    }

    private function winPayload(string $transactionId, string $amount, bool $creditToWallet, bool $completeRound): array
    {
        return [
            'PlayerCode' => $this->playerCode,
            'ProviderCode' => $this->providerCode,
            'GameRoundId' => $this->round,
            'GameCode' => $this->game->game_type,
            'TransactionId' => $transactionId,
            'TransactionDate' => $this->transactionDate,
            'Currency' => $this->currency,
            'Amount' => $amount,
            'Type' => TransactionTypeEnum::WIN->value,
            'RefundPreviousWin' => false,
            'CreditToWallet' => $creditToWallet,
            'CompleteRound' => $completeRound,
        ];
    }

    private function assertBalance(string $expected): void
    {
        $this->assertSame($expected, (string) $this->wallet->refresh()->balance);
    }

    private function betRecord(): TTHSWBetRecord
    {
        return TTHSWBetRecord::where('bet_id', $this->round)->firstOrFail();
    }
}
