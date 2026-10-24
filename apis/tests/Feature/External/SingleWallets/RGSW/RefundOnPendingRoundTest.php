<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\RGSW;

use App\Helpers\Common;
use App\Vendors\RGSW\Enums\BetRecordStatusEnum;
use App\Vendors\RGSW\Enums\StatusCodeEnum;
use PHPUnit\Framework\Attributes\Group;

/**
 * RGSW 未結算注單的退款測試（target/op_tickets#3100）
 *
 * 未結算（BET）的退款是「縮減這一注」：從 bet 扣掉退款額、把錢退回錢包。
 * 原本不論退多少都把狀態翻成 REFUND，於是同一局的第二筆合法部分退款會查不到注單而少退——
 * 現在只有把注全退完才翻 REFUND，退一部分的局仍留在 BET 等後續。
 *
 * 狀態留在 BET 之後，重送就不能再靠「狀態已變」擋住，改由 info.corrections 的重送守衛負責，
 * 所以本檔同時釘住「同一份 payload 重送只退一次」。
 */
class RefundOnPendingRoundTest extends RgswTestCase
{
    private const BET_ID = '6996281474';

    #[Group('rules/vendor-rgsw#R-15')]
    public function test_partial_refund_keeps_round_open_and_next_partial_refund_still_works(): void
    {
        // Arrange：未結算注單，下注 10
        $this->setBalance('1000');
        $betRecord = $this->createPendingBetRecord(self::BET_ID, bet: '10');

        // Act：先退 3，再退 4（金額不同，不會被重送守衛擋）
        $this->assertRgSuccess($this->refund('3.0000'));

        $betRecord->refresh();
        $this->assertSame(BetRecordStatusEnum::BET, $betRecord->status, '部分退款後這一注還在進行中，狀態必須留在 BET');
        $this->assertSame(0, Common::numCompare('7.0000', (string) $betRecord->valid_bet), '有效投注與 bet 同步遞減');

        $this->assertRgSuccess($this->refund('4.0000'));

        // Assert：兩筆都退到了，注單剩下 3
        $this->assertSame(0, Common::numCompare('1007.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(0, Common::numCompare('3.0000', (string) $betRecord->bet));
        $this->assertSame(0, Common::numCompare('3.0000', (string) $betRecord->valid_bet));
        $this->assertSame(0, Common::numCompare('-3.0000', (string) $betRecord->win_lose));
        $this->assertSame(BetRecordStatusEnum::BET, $betRecord->status);
    }

    #[Group('rules/vendor-rgsw#R-15')]
    public function test_partial_refund_clamps_valid_bet_at_zero(): void
    {
        // Arrange：bet() 允許 bet_real < bet_total，所以 valid_bet 可能本來就小於 bet。
        // 這時「與 bet 同步遞減」會把 valid_bet 算成負數，必須夾在 0
        $this->setBalance('1000');
        $betRecord = $this->createPendingBetRecord(self::BET_ID, bet: '10', validBet: '4');

        // Act：退 6，注單還剩 4 ⇒ 仍是部分退款
        $this->assertRgSuccess($this->refund('6.0000'));

        // Assert
        $betRecord->refresh();
        $this->assertSame(BetRecordStatusEnum::BET, $betRecord->status);
        $this->assertSame(0, Common::numCompare('4.0000', (string) $betRecord->bet));
        $this->assertSame(0, Common::numCompare('0.0000', (string) $betRecord->valid_bet), '有效投注不得為負');
    }

    #[Group('rules/vendor-rgsw#R-15')]
    public function test_refund_of_whole_remaining_bet_marks_round_refunded(): void
    {
        // Arrange
        $this->setBalance('1000');
        $betRecord = $this->createPendingBetRecord(self::BET_ID, bet: '10');

        // Act：先退 4，再把剩下的 6 退完
        $this->assertRgSuccess($this->refund('4.0000'));
        $this->assertRgSuccess($this->refund('6.0000'));

        // Assert：退乾淨才翻成 REFUND，有效投注歸零
        $this->assertSame(0, Common::numCompare('1010.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(0, Common::numCompare('0.0000', (string) $betRecord->bet));
        $this->assertSame(0, Common::numCompare('0.0000', (string) $betRecord->valid_bet));
        $this->assertSame(BetRecordStatusEnum::REFUND, $betRecord->status);
    }

    #[Group('rules/vendor-rgsw#R-16')]
    public function test_refund_exceeding_remaining_bet_is_rejected(): void
    {
        // Arrange：退款額大於下注額，照原式算下去 bet 會變負數、等於退還玩家沒下過的錢
        $this->setBalance('1000');
        $betRecord = $this->createPendingBetRecord(self::BET_ID, bet: '10');

        // Act
        $response = $this->refund('15.0000');

        // Assert：拒絕，錢包與注單都不動
        $response->assertOk()->assertJsonPath('status.code', StatusCodeEnum::TRANSACTION_FAILED->value);
        $this->assertSame(0, Common::numCompare('1000.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(0, Common::numCompare('10.0000', (string) $betRecord->bet));
        $this->assertSame(BetRecordStatusEnum::BET, $betRecord->status);
    }

    #[Group('rules/vendor-rgsw#R-17')]
    public function test_refund_with_negative_amount_is_rejected(): void
    {
        // Arrange：負數退款會讓加減反向，變成從玩家錢包倒扣
        $this->setBalance('1000');
        $betRecord = $this->createPendingBetRecord(self::BET_ID, bet: '10');

        // Act
        $response = $this->refund('-5.0000');

        // Assert
        $response->assertOk()->assertJsonPath('status.code', StatusCodeEnum::TRANSACTION_FAILED->value);
        $this->assertSame(0, Common::numCompare('1000.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(0, Common::numCompare('10.0000', (string) $betRecord->bet));
    }

    #[Group('rules/vendor-rgsw#R-24')]
    public function test_identical_partial_refund_retry_is_ignored(): void
    {
        // Arrange
        $this->setBalance('1000');
        $betRecord = $this->createPendingBetRecord(self::BET_ID, bet: '10');

        // Act：同一份 payload 送兩次
        $this->assertRgSuccess($this->refund('3.0000'));
        $this->assertRgSuccess($this->refund('3.0000'));

        // Assert：只退一次
        $this->assertSame(0, Common::numCompare('1003.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(0, Common::numCompare('7.0000', (string) $betRecord->bet));
    }

    private function refund(string $amount): \Illuminate\Testing\TestResponse
    {
        return $this->postWithToken(self::API_RGSW_REFUND, [
            'order_sn' => self::BET_ID,
            'amount' => $amount,
        ]);
    }
}
