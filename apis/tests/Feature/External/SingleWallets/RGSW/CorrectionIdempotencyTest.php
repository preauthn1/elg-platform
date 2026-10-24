<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\RGSW;

use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\RgswController;
use PHPUnit\Framework\Attributes\Group;

/**
 * RGSW 調整類回調（credit／debit／sumBet／已結算局的 refund）的重送防護測試
 *
 * 這四支的共同點是「疊加調整」：直接把金額加進原注單的 win／bet，錢包再依差額入帳。
 * RG 的請求沒有交易序號（生產 payload 只有 amount／bet_no／order_sn／username，且 order_sn 恆等於 bet_no），
 * 所以同一份 payload 送幾次就會入帳幾次——這正是 target/op_tickets#3100 要擋的。
 *
 * 守衛的判準是「同一局、同一種調整、同一個金額、而且在時間窗內」，因此本檔同時釘住兩個方向：
 * - 同一筆調整重送 → 錢包不動、回成功
 * - 換金額、換調整種類，或已超出時間窗 → 視為另一筆合法調整，照常入帳
 */
class CorrectionIdempotencyTest extends RgswTestCase
{
    private const BET_ID = '7070842814';

    #[Group('rules/vendor-rgsw#R-22')]
    #[Group('rules/vendor-rgsw#R-24')]
    public function test_credit_applies_once_and_identical_retry_is_ignored(): void
    {
        // Arrange：已結算注單（下注 5、派彩 10），玩家餘額 1000
        $this->setBalance('1000');
        $betRecord = $this->createSettledBetRecord(self::BET_ID, bet: '5', win: '10');

        $payload = $this->creditPayload('3.0000');

        // Act：同一份 payload 送兩次
        $first = $this->postWithToken(self::API_RGSW_CREDIT, $payload);
        $second = $this->postWithToken(self::API_RGSW_CREDIT, $payload);

        // Assert：兩次都回成功，但錢只加一次
        $this->assertRgSuccess($first);
        $this->assertRgSuccess($second);

        $this->assertSame(0, Common::numCompare('1003.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(0, Common::numCompare('13.0000', (string) $betRecord->win));
        $this->assertSame(1, $this->correctionCount($betRecord->info, 'credit'));
    }

    #[Group('rules/vendor-rgsw#R-24')]
    public function test_debit_identical_retry_is_ignored(): void
    {
        // Arrange：debit 的 amount 依 RG 規定恆為負值，代表直接的餘額增減量
        $this->setBalance('1000');
        $betRecord = $this->createSettledBetRecord(self::BET_ID, bet: '5', win: '10');

        $payload = $this->creditPayload('-4.0000');

        // Act
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_DEBIT, $payload));
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_DEBIT, $payload));

        // Assert：只扣一次
        $this->assertSame(0, Common::numCompare('996.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(0, Common::numCompare('6.0000', (string) $betRecord->win));
        $this->assertSame(1, $this->correctionCount($betRecord->info, 'debit'));
    }

    #[Group('rules/vendor-rgsw#R-25')]
    public function test_debit_retry_is_ignored_even_when_balance_cannot_cover_it_again(): void
    {
        // Arrange：扣款額大於「扣過一次之後的餘額」——事後整輪清帳幾乎必然是這個形狀。
        // 守衛若排在餘額檢查之後，重送會拿到「餘額不足」而不是「這筆處理過了」，
        // 三方就會把一筆已經成功的扣帳當成失敗（target/op_tickets#3100 圓桌審查發現）
        $this->setBalance('80');
        $betRecord = $this->createSettledBetRecord(self::BET_ID, bet: '5', win: '10');

        $payload = $this->creditPayload('-50.0000');

        // Act
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_DEBIT, $payload));
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_DEBIT, $payload));

        // Assert：只扣一次，重送被認出來而不是被判餘額不足
        $this->assertSame(0, Common::numCompare('30.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(1, $this->correctionCount($betRecord->info, 'debit'));
    }

    #[Group('rules/vendor-rgsw#R-23')]
    #[Group('rules/vendor-rgsw#R-24')]
    public function test_sum_bet_identical_retry_is_ignored(): void
    {
        // Arrange：sumBet 是追加投注，會再扣一次錢
        $this->setBalance('1000');
        $betRecord = $this->createSettledBetRecord(self::BET_ID, bet: '5', win: '10');

        $payload = $this->creditPayload('2.0000');

        // Act
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_SUM_BET, $payload));
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_SUM_BET, $payload));

        // Assert：只扣一次
        $this->assertSame(0, Common::numCompare('998.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(0, Common::numCompare('7.0000', (string) $betRecord->bet));
        $this->assertSame(1, $this->correctionCount($betRecord->info, 'sumBet'));
    }

    #[Group('rules/vendor-rgsw#R-25')]
    public function test_sum_bet_retry_is_ignored_even_when_balance_cannot_cover_it_again(): void
    {
        // Arrange：理由同 debit 那支——追加投注額大於扣過一次之後的餘額
        $this->setBalance('80');
        $betRecord = $this->createSettledBetRecord(self::BET_ID, bet: '5', win: '10');

        $payload = $this->creditPayload('50.0000');

        // Act
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_SUM_BET, $payload));
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_SUM_BET, $payload));

        // Assert
        $this->assertSame(0, Common::numCompare('30.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(1, $this->correctionCount($betRecord->info, 'sumBet'));
    }

    #[Group('rules/vendor-rgsw#R-18')]
    #[Group('rules/vendor-rgsw#R-24')]
    public function test_refund_on_settled_round_identical_retry_is_ignored(): void
    {
        // Arrange：已結算局的退款走疊加調整（狀態維持 BETPAYOUT）
        $this->setBalance('1000');
        $betRecord = $this->createSettledBetRecord(self::BET_ID, bet: '5', win: '10');

        $payload = ['order_sn' => self::BET_ID, 'amount' => '5.0000'];

        // Act
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_REFUND, $payload));
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_REFUND, $payload));

        // Assert：只退一次
        $this->assertSame(0, Common::numCompare('1005.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(0, Common::numCompare('15.0000', (string) $betRecord->win));
        $this->assertSame(1, $this->correctionCount($betRecord->info, 'refund'));
    }

    #[Group('rules/vendor-rgsw#R-26')]
    public function test_credit_with_different_amount_is_still_applied(): void
    {
        // Arrange：金額不同就不是同一筆調整，必須照常入帳
        $this->setBalance('1000');
        $betRecord = $this->createSettledBetRecord(self::BET_ID, bet: '5', win: '10');

        // Act
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_CREDIT, $this->creditPayload('3.0000')));
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_CREDIT, $this->creditPayload('2.0000')));

        // Assert：兩筆都入帳
        $this->assertSame(0, Common::numCompare('1005.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(2, $this->correctionCount($betRecord->info, 'credit'));
    }

    #[Group('rules/vendor-rgsw#R-26')]
    public function test_credit_is_not_swallowed_by_a_same_amount_refund_on_the_same_round(): void
    {
        // Arrange：同一局、同一個金額、時間窗內，但一筆是退款、一筆是補款——
        // 守衛若不比對調整種類，第二筆合法的 credit 會被當成 refund 的重送吞掉（玩家少拿錢）
        $this->setBalance('1000');
        $betRecord = $this->createSettledBetRecord(self::BET_ID, bet: '5', win: '10');

        // Act
        $this->assertRgSuccess($this->postWithToken(
            self::API_RGSW_REFUND,
            ['order_sn' => self::BET_ID, 'amount' => '3.0000']
        ));
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_CREDIT, $this->creditPayload('3.0000')));

        // Assert：兩筆都入帳
        $this->assertSame(0, Common::numCompare('1006.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(1, $this->correctionCount($betRecord->info, 'refund'));
        $this->assertSame(1, $this->correctionCount($betRecord->info, 'credit'));
    }

    #[Group('rules/vendor-rgsw#R-26')]
    public function test_retry_carrying_an_extra_field_is_still_recognised(): void
    {
        // Arrange：守衛只比對「決定動到哪一局」的欄位加金額，不比整包 payload。
        // RG 哪天在請求裡多帶一個會變動的欄位（生產上的 payout 就帶了每筆都不同的 payout_time），
        // 整包比對會永遠不相等而讓守衛靜默失效
        $this->setBalance('1000');
        $betRecord = $this->createSettledBetRecord(self::BET_ID, bet: '5', win: '10');

        // Act
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_CREDIT, $this->creditPayload('3.0000')));
        $this->assertRgSuccess($this->postWithToken(
            self::API_RGSW_CREDIT,
            $this->creditPayload('3.0000') + ['trace_id' => 'retry-1', 'sent_at' => '2026-09-09 12:00:01']
        ));

        // Assert：仍然只入帳一次
        $this->assertSame(0, Common::numCompare('1003.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(1, $this->correctionCount($betRecord->info, 'credit'));
    }

    #[Group('rules/vendor-rgsw#R-26')]
    public function test_retry_with_a_differently_formatted_amount_is_still_recognised(): void
    {
        // Arrange：金額用數值比對而非字串相等，"3" 與 "3.0000" 是同一筆錢
        $this->setBalance('1000');
        $betRecord = $this->createSettledBetRecord(self::BET_ID, bet: '5', win: '10');

        // Act
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_CREDIT, $this->creditPayload('3.0000')));
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_CREDIT, $this->creditPayload('3')));

        // Assert
        $this->assertSame(0, Common::numCompare('1003.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(1, $this->correctionCount($betRecord->info, 'credit'));
    }

    #[Group('rules/vendor-rgsw#R-26')]
    public function test_credit_outside_duplicate_window_is_applied_again(): void
    {
        // Arrange：內容一模一樣，但已經超出時間窗 ⇒ 視為另一筆合法調整
        $this->setBalance('1000');
        $betRecord = $this->createSettledBetRecord(self::BET_ID, bet: '5', win: '10');

        $payload = $this->creditPayload('3.0000');

        // Act：第二次呼叫時把時鐘往前撥超過時間窗（引用常數，值調整時測試才不會靜默測到別的東西）
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_CREDIT, $payload));
        $this->travel(RgswController::DUPLICATE_WINDOW_SECONDS + 60)->seconds();
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_CREDIT, $payload));

        // Assert：兩筆都入帳
        $this->assertSame(0, Common::numCompare('1006.0000', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(2, $this->correctionCount($betRecord->info, 'credit'));
    }

    /** credit／debit／sumBet 三支的 payload 形狀相同，只有金額不一樣 */
    private function creditPayload(string $amount): array
    {
        return [
            'username' => $this->playerCode,
            'bet_no' => self::BET_ID,
            'order_sn' => self::BET_ID,
            'amount' => $amount,
        ];
    }

    /** 數某一種調整在注單 info.corrections 裡被記了幾筆 */
    private function correctionCount(?string $info, string $type): int
    {
        $decoded = json_decode((string) $info, true);
        $corrections = is_array($decoded) ? ($decoded['corrections'] ?? []) : [];

        if (!is_array($corrections)) {
            return 0;
        }

        return count(array_filter(
            $corrections,
            static fn ($correction): bool => is_array($correction) && ($correction['type'] ?? null) === $type
        ));
    }
}
