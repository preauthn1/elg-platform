<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\TTHSW;

use App\Enums\BetRecordStatusEnum;
use App\Vendors\Models\TTHSWBetRecord;
use App\Vendors\Models\TTHSWDealRecord;
use App\Vendors\TTHSW\Enums\ErrorCodeEnum;
use App\Vendors\TTHSW\Enums\TransactionTypeEnum;
use PHPUnit\Framework\Attributes\Group;

/**
 * 撤單（refund Type=9 CANCEL_TRANSACTION）的冪等（target/op_tickets#3099）。
 *
 * 原本的去重只認撤單自己的 TransactionId，被撤的原扣款從不被標記，於是：
 * (1) 三方換一個 TransactionId 重送同一筆撤單，每次都會再退一次；
 * (2) 整局已 CANCEL_ROUND 退光之後，撤單仍能在 bet 已為 0 的注單上再退一次。
 */
class CancelTransactionIdempotencyTest extends TTHSWTestCase
{
    private string $round = 'tth-round-3099';

    private string $firstBetTransactionId = 'tth-bet-1';

    private string $secondBetTransactionId = 'tth-bet-2';

    private string $transactionDate = '2026-09-08T10:00:00.000+00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallet->update(['balance' => '1000.0000']);
    }

    #[Group('rules/vendor-tthsw#R-29')]
    public function test_cancel_transaction_rejects_retry_with_new_transaction_id(): void
    {
        $this->placeBet($this->firstBetTransactionId, '100');

        $this->cancelTransaction('tth-cancel-1', $this->firstBetTransactionId)
            ->assertStatus(200)
            ->assertJsonPath('code', ErrorCodeEnum::SUCCESS->value);

        $balanceAfterCancel = (string) $this->wallet->refresh()->balance;
        $betAfterCancel = (string) $this->betRecord()->bet;

        $firstCancel = TTHSWDealRecord::where('game_round_id', $this->round)
            ->where('type', TransactionTypeEnum::CANCEL_TRANSACTION->value)
            ->firstOrFail();
        $betRecordId = (string) $this->betRecord()->id;

        // 三方換新的 TransactionId 重送同一筆撤單，連送五次
        for ($retry = 2; $retry <= 6; $retry++) {
            $this->cancelTransaction("tth-cancel-{$retry}", $this->firstBetTransactionId)
                ->assertStatus(200)
                ->assertJsonPath('code', ErrorCodeEnum::GAME_ROUND_TRANSACTION_DUPLICATED->value)
                // TransactionId echo 三方這次送的新值，餘額則回第一次撤單當時的結果
                ->assertJsonPath('value.TransactionId', "tth-cancel-{$retry}")
                ->assertJsonPath('value.StartBalance', (string) $firstCancel->start_balance)
                ->assertJsonPath('value.EndBalance', (string) $firstCancel->end_balance)
                // ExternalTransactionId 必須與成功回應同一個命名空間（注單 id），不能是 deal record id
                ->assertJsonPath('value.ExternalTransactionId', $betRecordId);
        }

        $this->assertSame($balanceAfterCancel, (string) $this->wallet->refresh()->balance, '重送撤單不得再退款');
        $this->assertSame($betAfterCancel, (string) $this->betRecord()->bet, '重送撤單不得再扣注單投注額');

        $this->assertSame(
            1,
            TTHSWDealRecord::where('game_round_id', $this->round)
                ->where('type', TransactionTypeEnum::CANCEL_TRANSACTION->value)
                ->count(),
            '被擋下的重送不得再寫一筆撤單紀錄'
        );
    }

    #[Group('rules/vendor-tthsw#R-32')]
    public function test_cancel_transaction_rejected_after_cancel_round(): void
    {
        $this->placeBet($this->firstBetTransactionId, '100');

        $this->cancelRound('tth-cancel-round-1')
            ->assertStatus(200)
            ->assertJsonPath('code', ErrorCodeEnum::SUCCESS->value);

        $balanceAfterCancelRound = (string) $this->wallet->refresh()->balance;
        $this->assertSame(BetRecordStatusEnum::REFUND, $this->betRecord()->status);

        // 整局已退光，這時再撤單會在 bet 已為 0 的注單上再退一次
        $this->cancelTransaction('tth-cancel-after-round', $this->firstBetTransactionId)
            ->assertStatus(200)
            ->assertJsonPath('code', ErrorCodeEnum::GAME_ROUND_REFUNDED->value);

        $this->assertSame($balanceAfterCancelRound, (string) $this->wallet->refresh()->balance, '整局退款後不得再退');
        $this->assertSame('0.0000', (string) $this->betRecord()->bet, '注單投注額不得被扣成負數');
    }

    /**
     * 閘門的粒度是「同一筆原交易」，不是「同一局」：同局的另一筆下注仍必須撤得掉。
     */
    #[Group('rules/vendor-tthsw#R-28')]
    public function test_cancel_transaction_allows_each_original_transaction_once(): void
    {
        $this->placeBet($this->firstBetTransactionId, '100');
        $this->placeBet($this->secondBetTransactionId, '50');

        $this->cancelTransaction('tth-cancel-a', $this->firstBetTransactionId)
            ->assertStatus(200)
            ->assertJsonPath('code', ErrorCodeEnum::SUCCESS->value);

        $this->cancelTransaction('tth-cancel-b', $this->secondBetTransactionId)
            ->assertStatus(200)
            ->assertJsonPath('code', ErrorCodeEnum::SUCCESS->value);

        $this->assertSame('1000.0000', (string) $this->wallet->refresh()->balance, '兩筆下注各撤一次應全額退回');
        $this->assertSame(
            2,
            TTHSWDealRecord::where('game_round_id', $this->round)
                ->where('type', TransactionTypeEnum::CANCEL_TRANSACTION->value)
                ->count()
        );
    }

    /**
     * 上游 processCancelTransaction() 以 SQL 找原交易，`transaction_id` 的 collation 是 utf8mb4_unicode_ci
     * （大小寫不敏感）；閘門若在 PHP 端用 byte 比對，大小寫變體就會在上游命中原交易、在閘門比不中既有撤單列，
     * 於是放行再退一次。
     */
    #[Group('rules/vendor-tthsw#R-29')]
    public function test_cancel_transaction_rejects_case_variant_of_original_transaction_id(): void
    {
        $this->placeBet($this->firstBetTransactionId, '100');

        $this->cancelTransaction('tth-cancel-1', $this->firstBetTransactionId)
            ->assertStatus(200)
            ->assertJsonPath('code', ErrorCodeEnum::SUCCESS->value);

        $balanceAfterCancel = (string) $this->wallet->refresh()->balance;
        $betAfterCancel = (string) $this->betRecord()->bet;

        $caseVariant = strtoupper($this->firstBetTransactionId);
        $this->assertNotSame($this->firstBetTransactionId, $caseVariant, '前置：變體必須與原值不同才有意義');

        $this->cancelTransaction('tth-cancel-case-variant', $caseVariant)
            ->assertStatus(200)
            ->assertJsonPath('code', ErrorCodeEnum::GAME_ROUND_TRANSACTION_DUPLICATED->value);

        $this->assertSame($balanceAfterCancel, (string) $this->wallet->refresh()->balance, '大小寫變體不得再退一次');
        $this->assertSame($betAfterCancel, (string) $this->betRecord()->bet);
        $this->assertSame(
            1,
            TTHSWDealRecord::where('game_round_id', $this->round)
                ->where('type', TransactionTypeEnum::CANCEL_TRANSACTION->value)
                ->count()
        );
    }

    private function placeBet(string $transactionId, string $amount): void
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

        // TTHSW 的失敗一律回 907，訊息才看得出原因，斷言時一併帶出
        $this->assertSame(
            ErrorCodeEnum::SUCCESS->value,
            $response->json('code'),
            '下注失敗：' . (string) $response->json('message')
        );
    }

    private function cancelTransaction(string $transactionId, string $originalTransactionId): \Illuminate\Testing\TestResponse
    {
        return $this->postSigned(self::API_TTHSW_REFUND, [
            'PlayerCode' => $this->playerCode,
            'ProviderCode' => $this->providerCode,
            'GameRoundId' => $this->round,
            'GameCode' => $this->game->game_type,
            'TransactionId' => $transactionId,
            'OriginalTransactionId' => $originalTransactionId,
            'TransactionDate' => $this->transactionDate,
            'Currency' => $this->currency,
            'Amount' => '0',
            'Type' => TransactionTypeEnum::CANCEL_TRANSACTION->value,
        ]);
    }

    private function cancelRound(string $transactionId): \Illuminate\Testing\TestResponse
    {
        return $this->postSigned(self::API_TTHSW_REFUND, [
            'PlayerCode' => $this->playerCode,
            'ProviderCode' => $this->providerCode,
            'GameRoundId' => $this->round,
            'GameCode' => $this->game->game_type,
            'TransactionId' => $transactionId,
            'TransactionDate' => $this->transactionDate,
            'Currency' => $this->currency,
            'Amount' => '0',
            'Type' => TransactionTypeEnum::CANCEL_ROUND->value,
        ]);
    }

    private function betRecord(): TTHSWBetRecord
    {
        return TTHSWBetRecord::where('bet_id', $this->round)->firstOrFail();
    }
}
