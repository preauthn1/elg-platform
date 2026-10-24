<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\AFBSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\AFBSW\BetRecordStatusEnum;
use App\Vendors\Models\AfbswBetRecord;
use PHPUnit\Framework\Attributes\Group;

/**
 * AFB refund 回調：取消投注必須把注單投注量歸零，退款注單不得計入 TBR／SBR 的投注額
 * （target/op_tickets#3067：2026-09-05 事故日 4 筆退款注單被 SR11／SR12 與三層代理返利當成有效投注）
 *
 * php artisan test tests/Feature/External/SingleWallets/AFBSW/AFBSwRefundTest.php --compact
 */
class AFBSwRefundTest extends AFBSwTestCase
{
    /**
     * 情境：bet 已扣 6.00 真金，AFB 送 refund 取消投注
     * 預期：本金退回真金錢包；注單 bet／valid_bet／payout／win_lose 全歸零、status=REFOUND；TBR 投注量為 0
     */
    #[Group('rules/vendor-afbsw#R-13')]
    public function test_refund_zeroes_bet_and_writes_zero_turnover_to_total_bet_records()
    {
        $betRecord = $this->createPlacedBetRecord('1788622749122374051', '6.0000');
        $this->wallet->update(['balance' => '94.0000']);

        $response = $this->postJson($this->baseurl, $this->refundPayload('1788622749122374051', '6.00'));

        $response->assertStatus(200)->assertJson(['status' => 0, 'balance' => 100.0]);

        $this->wallet->refresh();
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->wallet->balance, 4));

        $betRecord->refresh();
        $this->assertSame(BetRecordStatusEnum::REFOUND->value, (int) $betRecord->status);
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->valid_bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->payout, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->win_lose, 4));
        $this->assertNotNull($betRecord->payout_time);

        $totalBetRecord = TotalBetRecord::query()
            ->where('vendor_id', VendorIdEnum::AFBSW->value)
            ->where('bet_record_id', $betRecord->id)
            ->first();
        $this->assertNotNull($totalBetRecord);
        $this->assertSame('0.0000', Common::numAdd('0', (string) $totalBetRecord->bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $totalBetRecord->valid_bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $totalBetRecord->win_lose, 4));
    }

    /**
     * 情境：bet 6.00 其中 2.00 由禮碼支付，AFB 送 refund
     * 預期：真金退 4.00、禮碼退 2.00（各回各的錢包），不得把禮碼部分當真金派彩
     */
    #[Group('rules/vendor-afbsw#R-14')]
    public function test_refund_returns_rolling_chip_portion_to_rolling_chip_wallet()
    {
        $this->assertNotNull($this->rollingChipWallet, '測試前提：商戶已開啟禮碼錢包');

        $betRecord = $this->createPlacedBetRecord('1788622927325386051', '6.0000', '2.0000');
        $this->wallet->update(['balance' => '96.0000']);
        $this->rollingChipWallet->update(['balance' => '0.0000']);

        $response = $this->postJson($this->baseurl, $this->refundPayload('1788622927325386051', '6.00'));

        $response->assertStatus(200)->assertJson(['status' => 0]);

        $this->wallet->refresh();
        $this->rollingChipWallet->refresh();
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->wallet->balance, 4));
        $this->assertSame('2.0000', Common::numAdd('0', (string) $this->rollingChipWallet->balance, 4));

        $betRecord->refresh();
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->rolling_chips, 4));
    }

    /**
     * 情境：商戶匯率 1:100，AFB 以廠商幣值送 amount=600 退我方 6.00 的注單
     * 預期：退款金額依 rate 換算後與原注金一致，錢包 +6.00；回傳餘額為廠商幣值
     */
    #[Group('rules/vendor-afbsw#R-14')]
    public function test_refund_converts_vendor_amount_by_rate()
    {
        $this->vendorInfo->rate = 100.0;
        $this->vendorInfo->save();

        $betRecord = $this->createPlacedBetRecord('1788623295743231099', '6.0000');
        $this->wallet->update(['balance' => '94.0000']);

        $response = $this->postJson($this->baseurl, $this->refundPayload('1788623295743231099', '600.00'));

        $response->assertStatus(200)->assertJson(['status' => 0, 'balance' => 10000.0]);

        $this->wallet->refresh();
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->wallet->balance, 4));

        $betRecord->refresh();
        $this->assertSame(BetRecordStatusEnum::REFOUND->value, (int) $betRecord->status);
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->bet, 4));
    }

    /**
     * 情境：AFB 送來的 amount（3.00）與原注金（6.00）不符（api.md §5.5 未定義部分退款）
     * 預期：拒收回 status=1，錢包與注單都不動，留給 AFB 重送或人工處理，不得靜默多退或少退
     */
    #[Group('rules/vendor-afbsw#R-15')]
    public function test_refund_is_rejected_when_amount_differs_from_placed_bet()
    {
        $betRecord = $this->createPlacedBetRecord('1788623295743231088', '6.0000');
        $this->wallet->update(['balance' => '94.0000']);

        $response = $this->postJson($this->baseurl, $this->refundPayload('1788623295743231088', '3.00'));

        $response->assertStatus(200)->assertJson(['status' => 1, 'message' => 'Refund amount mismatch']);

        $this->wallet->refresh();
        $this->assertSame('94.0000', Common::numAdd('0', (string) $this->wallet->balance, 4));

        $betRecord->refresh();
        $this->assertSame(BetRecordStatusEnum::BET->value, (int) $betRecord->status);
        $this->assertSame('6.0000', Common::numAdd('0', (string) $betRecord->bet, 4));
        $this->assertSame(
            0,
            TotalBetRecord::query()->where('vendor_id', VendorIdEnum::AFBSW->value)->where('bet_record_id', $betRecord->id)->count()
        );
    }

    /**
     * 情境：同一筆 transactionId 重複送 refund
     * 預期：第二次被拒（already refunded），錢包不重複退款
     */
    #[Group('rules/vendor-afbsw#R-16')]
    public function test_refund_is_rejected_when_bet_already_refunded()
    {
        $this->createPlacedBetRecord('1788623084505201051', '2.0000');
        $this->wallet->update(['balance' => '98.0000']);

        $this->postJson($this->baseurl, $this->refundPayload('1788623084505201051', '2.00'))
            ->assertStatus(200)->assertJson(['status' => 0]);
        $response = $this->postJson($this->baseurl, $this->refundPayload('1788623084505201051', '2.00'));

        $response->assertStatus(200)->assertJson(['status' => 1, 'message' => 'Bet record already refunded']);

        $this->wallet->refresh();
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->wallet->balance, 4));
        $this->assertSame(1, AfbswBetRecord::query()->where('bet_id', '1788623084505201051')->count());
    }

    /**
     * 情境：refund 的 transactionId 查無注單（下注當時已回滾、從未扣款）
     * 預期：回 Bet transaction not found，錢包不動
     */
    #[Group('rules/vendor-afbsw#R-16')]
    public function test_refund_returns_not_found_when_bet_does_not_exist()
    {
        $this->wallet->update(['balance' => '100.0000']);

        $response = $this->postJson($this->baseurl, $this->refundPayload('1788623295743231051', '2.40'));

        $response->assertStatus(200)->assertJson(['status' => 1, 'message' => 'Bet transaction not found']);

        $this->wallet->refresh();
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->wallet->balance, 4));
    }
}
