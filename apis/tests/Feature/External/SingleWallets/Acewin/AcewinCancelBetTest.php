<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Acewin;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\AcewinSW\BetRecordStatusEnum;
use App\Vendors\Models\AcewinBetRecord;
use PHPUnit\Framework\Attributes\Group;

/**
 * Acewin cancelBet 回調：取消投注必須把注單投注量歸零，被取消的注單不得計入 TBR／SBR 的投注額
 * （target/op_tickets#3078，與 AFBSW 的 target/op_tickets#3067 是同一型缺陷）
 *
 * php artisan test tests/Feature/External/SingleWallets/Acewin/AcewinCancelBetTest.php --compact
 */
class AcewinCancelBetTest extends AcewinTestCase
{
    /**
     * 情境：bet 扣了 10.00 真金（該回合全輸），AcewinSW 送 cancelBet 撤銷該注單
     * 預期：本金退回真金錢包；注單 bet／valid_bet／win_lose 全歸零、status=ROLLBACK；TBR 投注量為 0
     */
    #[Group('rules/vendor-acewin#R-14')]
    public function test_cancel_bet_zeroes_turnover_and_refunds_wallet(): void
    {
        $round = '100029127135';
        $this->wallet->update(['balance' => '100.0000']);

        $this->postJson($this->baseurl . '/bet', $this->betPayload($round, '10', '0'), $this->authHeaders())
            ->assertStatus(200)->assertJson(['errorCode' => 0]);

        $this->wallet->refresh();
        $this->assertSame('90.0000', Common::numAdd('0', (string) $this->wallet->balance, 4), '前提：bet 已扣 10.00');

        $response = $this->postJson($this->baseurl . '/cancelBet', $this->cancelBetPayload($round, '10'), $this->authHeaders());

        $response->assertStatus(200)->assertJson(['errorCode' => 0, 'balance' => 100.0]);

        $this->wallet->refresh();
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->wallet->balance, 4));

        $betRecord = AcewinBetRecord::query()->where('bet_id', $round)->firstOrFail();
        $this->assertSame(BetRecordStatusEnum::ROLLBACK->value, (int) $betRecord->status);
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->valid_bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->win_lose, 4));
        $this->assertNotNull($betRecord->payout_time);

        $totalBetRecord = TotalBetRecord::query()
            ->where('vendor_id', VendorIdEnum::ACEWIN->value)
            ->where('bet_record_id', $betRecord->id)
            ->firstOrFail();
        $this->assertSame('0.0000', Common::numAdd('0', (string) $totalBetRecord->bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $totalBetRecord->valid_bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $totalBetRecord->win_lose, 4));
    }

    /**
     * 情境：bet 10.00 其中 2.00 由禮碼支付，AcewinSW 送 cancelBet
     * 預期：真金退 8.00、禮碼退 2.00（各回各的錢包），注單 rolling_chip 歸零
     */
    #[Group('rules/vendor-acewin#R-14')]
    public function test_cancel_bet_returns_rolling_chip_portion_to_rolling_chip_wallet(): void
    {
        $this->assertNotNull($this->rollingChipWallet, '測試前提：商戶已開啟禮碼錢包');

        $round = '100029127136';
        $this->wallet->update(['balance' => '100.0000']);
        $this->rollingChipWallet->update(['balance' => '2.0000']);

        $this->postJson($this->baseurl . '/bet', $this->betPayload($round, '10', '0'), $this->authHeaders())
            ->assertStatus(200)->assertJson(['errorCode' => 0]);

        $this->wallet->refresh();
        $this->rollingChipWallet->refresh();
        $this->assertSame('92.0000', Common::numAdd('0', (string) $this->wallet->balance, 4), '前提：真金扣 8.00');
        $this->assertSame('0.0000', Common::numAdd('0', (string) $this->rollingChipWallet->balance, 4), '前提：禮碼扣 2.00');

        $this->postJson($this->baseurl . '/cancelBet', $this->cancelBetPayload($round, '10'), $this->authHeaders())
            ->assertStatus(200)->assertJson(['errorCode' => 0]);

        $this->wallet->refresh();
        $this->rollingChipWallet->refresh();
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->wallet->balance, 4));
        $this->assertSame('2.0000', Common::numAdd('0', (string) $this->rollingChipWallet->balance, 4));

        $betRecord = AcewinBetRecord::query()->where('bet_id', $round)->firstOrFail();
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->rolling_chip, 4));
    }

    /**
     * 情境：AcewinSW 送來的 betAmount（3.00）與原注金（10.00）不符
     * 預期：拒收回 errorCode=3，錢包與注單都不動——不得以請求金額覆寫既有注單金額
     */
    #[Group('rules/vendor-acewin#R-15')]
    public function test_cancel_bet_is_rejected_when_amount_differs_from_placed_bet(): void
    {
        $round = '100029127137';
        $this->wallet->update(['balance' => '100.0000']);

        $this->postJson($this->baseurl . '/bet', $this->betPayload($round, '10', '0'), $this->authHeaders())
            ->assertStatus(200)->assertJson(['errorCode' => 0]);

        $response = $this->postJson($this->baseurl . '/cancelBet', $this->cancelBetPayload($round, '3'), $this->authHeaders());

        $response->assertStatus(200)->assertJson(['errorCode' => 3]);

        $this->wallet->refresh();
        $this->assertSame('90.0000', Common::numAdd('0', (string) $this->wallet->balance, 4));

        $betRecord = AcewinBetRecord::query()->where('bet_id', $round)->firstOrFail();
        $this->assertSame(BetRecordStatusEnum::SETTLE->value, (int) $betRecord->status);
        $this->assertSame('10.0000', Common::numAdd('0', (string) $betRecord->bet, 4));
    }

    /**
     * 情境：同一筆 round 重複送 cancelBet（AcewinSW 最多重試 10 次，api.md「cancelBet」節）
     * 預期：第二次回 errorCode=1，錢包不重複退款
     */
    #[Group('rules/vendor-acewin#R-16')]
    public function test_cancel_bet_is_rejected_when_already_rolled_back(): void
    {
        $round = '100029127138';
        $this->wallet->update(['balance' => '100.0000']);

        $this->postJson($this->baseurl . '/bet', $this->betPayload($round, '10', '0'), $this->authHeaders())
            ->assertStatus(200)->assertJson(['errorCode' => 0]);
        $this->postJson($this->baseurl . '/cancelBet', $this->cancelBetPayload($round, '10'), $this->authHeaders())
            ->assertStatus(200)->assertJson(['errorCode' => 0]);

        $response = $this->postJson($this->baseurl . '/cancelBet', $this->cancelBetPayload($round, '10'), $this->authHeaders());

        $response->assertStatus(200)->assertJson(['errorCode' => 1]);

        $this->wallet->refresh();
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->wallet->balance, 4));
    }

    /**
     * 情境：cancelBet 的 round 查無注單（bet 當時未落地）
     * 預期：回 errorCode=2，錢包不動
     */
    #[Group('rules/vendor-acewin#R-16')]
    public function test_cancel_bet_returns_not_found_when_bet_does_not_exist(): void
    {
        $this->wallet->update(['balance' => '100.0000']);

        $response = $this->postJson($this->baseurl . '/cancelBet', $this->cancelBetPayload('100029127139', '10'), $this->authHeaders());

        $response->assertStatus(200)->assertJson(['errorCode' => 2]);

        $this->wallet->refresh();
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->wallet->balance, 4));
    }
}
