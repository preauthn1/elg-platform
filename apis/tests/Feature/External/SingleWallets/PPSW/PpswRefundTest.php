<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PPSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\Models\PpswBetRecord;
use App\Vendors\Models\PpswDealRecord;
use App\Vendors\PPSW\BetRecordStatusEnum;

/**
 * 驗證 PPSW refund 端點行為：
 *   正常退款、找不到原始 bet、重複 reference 冪等。
 *
 * php artisan test tests/Feature/External/SingleWallets/PPSW/PpswRefundTest.php --compact
 */
class PpswRefundTest extends PpswTestCase
{
    private string $roundId = 'refund-round-001';

    private string $betAmount = '30.0';

    private string $betReference = 'refund-bet-ref-001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->member->update(['balance' => '1000.0000']);
    }

    private function doBet(): void
    {
        $this->postJson("{$this->baseurl}/bet.html", $this->withHash([
            'amount' => $this->betAmount,
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => $this->betReference,
            'roundDetails' => 'spin',
            'roundId' => $this->roundId,
            'timestamp' => '1775602502471',
            'userId' => $this->username,
        ]))->assertJson(['error' => 0]);
    }

    /**
     * 正常退款：餘額恢復、BetRecord(status=REFUND)、建立 TotalBetRecord。
     */
    public function test_refund_restores_balance_and_marks_refund(): void
    {
        $this->doBet();

        $balanceAfterBet = $this->member->fresh()->balance;

        // roundId 在 refund 中為選填，但已向 PP 申請啟用，故必須傳入。
        // ppsw_deal_records.bet_id NOT NULL 約束依賴此欄位，省略會導致 DB 錯誤。
        $payload = $this->withHash([
            'providerId' => $this->providerId,
            'reference' => $this->betReference,
            'roundId' => $this->roundId,
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/refund.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 0, 'description' => 'Success']);

        $betRecord = PpswBetRecord::first();
        $this->assertEquals(BetRecordStatusEnum::REFUND, $betRecord->status);

        $this->assertEquals(1, TotalBetRecord::count(), '退款應建立 TotalBetRecord');

        // 退款後餘額應恢復為 bet 之前的值（bet 扣款 → refund 還款）
        $this->member->refresh();
        $expectedBalance = Common::numAdd($balanceAfterBet, Common::numAdd('0', $this->betAmount, 4), 4);
        $this->assertEquals($expectedBalance, $this->member->balance, '退款後餘額應恢復');
    }

    /**
     * 找不到原始 bet：官方規格規定回傳 error:0，不執行任何操作。
     */
    public function test_refund_with_no_prior_bet_returns_success(): void
    {
        $payload = $this->withHash([
            'providerId' => $this->providerId,
            'reference' => 'non-existent-bet-reference-xyz',
            'roundId' => 'non-existent-round-xyz',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/refund.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 0]);

        // 不應建立任何記錄（DealRecord 因 rollback 也不存在）
        $this->assertEquals(0, PpswBetRecord::count());
        $this->assertEquals(0, PpswDealRecord::count());
        $this->assertEquals(0, TotalBetRecord::count());
    }

    /**
     * 重複 reference 退款：isDealRecordFinished 早期返回，餘額不重複退還。
     */
    public function test_refund_duplicate_reference_is_idempotent(): void
    {
        $this->doBet();

        $payload = $this->withHash([
            'providerId' => $this->providerId,
            'reference' => $this->betReference,
            'roundId' => $this->roundId,
            'userId' => $this->username,
        ]);

        // 第一次退款
        $this->postJson("{$this->baseurl}/refund.html", $payload)
            ->assertJson(['error' => 0]);

        $balanceAfterFirstRefund = $this->member->fresh()->balance;
        $tbrCount = TotalBetRecord::count();

        // 第二次退款（相同 reference）
        $this->postJson("{$this->baseurl}/refund.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 0]);

        $this->member->refresh();
        $this->assertEquals($balanceAfterFirstRefund, $this->member->balance, '餘額不應重複退還');
        $this->assertEquals($tbrCount, TotalBetRecord::count(), 'TBR 不應重複建立');
        $this->assertEquals(BetRecordStatusEnum::REFUND, PpswBetRecord::first()->status);
    }
}
