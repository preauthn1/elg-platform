<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\AiGamingSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\Models\AiGamingSwBetRecord;
use PHPUnit\Framework\Attributes\Group;

/**
 * payload 取自 2026-08-31 dev 實測 callback（tx 459092、遊戲 luminadrift；gameCode 改為測試遊戲）
 *
 * php artisan test tests/Feature/External/SingleWallets/AiGamingSW/DeductTest.php --compact
 */
class DeductTest extends AiGamingSWTestCase
{
    private function makePostData(array $overrides = []): array
    {
        return $this->signedPostData('deduct', array_merge([
            'amount' => '0.4',
            'transactionId' => '459092',
            'betTime' => '2026-08-31T17:39:21.687229933',
            'gameCode' => 'TEST',
            'gameTypeName' => 'TEST',
            'gameInfo' => '{}',
            'hasBonus' => true,
        ], $overrides));
    }

    /**
     * 情境：餘額足夠，正常投注扣款（實測 payload：ISO8601 納秒、UTC+8 無時區後綴）
     * 預期：扣除 amount 建立注單（status=0 未結算）、betTime 換算 UTC 落帳，TBR 於結算才建
     */
    #[Group('rules/vendor-aigamingsw#R-8')]
    #[Group('rules/vendor-aigamingsw#R-9')]
    public function test_deduct_creates_bet_record_and_deducts_balance()
    {
        $this->member->update(['balance' => '100.0000']);

        $response = $this->postJson("{$this->baseurl}/deduct", $this->makePostData());
        $betRecord = AiGamingSwBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'errorCode' => 0,
            'errorMessage' => 'success',
            'balance' => 99.6,
        ]);

        $this->assertSame('99.6000', Common::numAdd('0', (string) $this->member->balance, 4));
        $this->assertSame(1, AiGamingSwBetRecord::count());
        $this->assertSame('459092', $betRecord->bet_id);
        $this->assertSame(0, (int) $betRecord->status);
        $this->assertSame('0.4000', Common::numAdd('0', (string) $betRecord->bet, 4));
        $this->assertSame('0.4000', Common::numAdd('0', (string) $betRecord->valid_bet, 4));
        $this->assertSame('-0.4000', Common::numAdd('0', (string) $betRecord->win_lose, 4));
        // 實測 betTime 為 UTC+8（17:39:21），落帳須換算 UTC（09:39:21）
        $this->assertSame('2026-08-31 09:39:21', (string) $betRecord->bet_time);
        $this->assertSame(0, TotalBetRecord::count());
    }

    /**
     * 情境：vendor_info.rate 非 1:1（1:1000）
     * 預期：amount 除以 rate 存平台單位，回傳 balance 乘回廠商單位
     */
    #[Group('rules/vendor-aigamingsw#R-9')]
    public function test_deduct_converts_amount_by_rate_when_not_one_to_one()
    {
        $this->vendorInfo->update(['rate' => 1000]);
        $this->member->update(['balance' => '100.0000']);

        $response = $this->postJson("{$this->baseurl}/deduct", $this->makePostData([
            'amount' => '400',
        ]));
        $betRecord = AiGamingSwBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'errorCode' => 0,
            'balance' => 99600.0,
        ]);

        $this->assertSame('99.6000', Common::numAdd('0', (string) $this->member->balance, 4));
        $this->assertSame('0.4000', Common::numAdd('0', (string) $betRecord->bet, 4));
    }

    /**
     * 情境：相同 transactionId 重複 deduct
     * 預期：回錯誤碼 5003，不重複扣款，注單僅一筆
     */
    #[Group('rules/vendor-aigamingsw#R-12')]
    public function test_deduct_rejects_duplicate_transaction_id()
    {
        $this->member->update(['balance' => '100.0000']);

        $this->postJson("{$this->baseurl}/deduct", $this->makePostData());
        $response = $this->postJson("{$this->baseurl}/deduct", $this->makePostData());
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['errorCode' => 5003]);
        $this->assertSame(1, AiGamingSwBetRecord::count());
        $this->assertSame('99.6000', Common::numAdd('0', (string) $this->member->balance, 4));
    }

    /**
     * 情境：餘額不足
     * 預期：回錯誤碼 5，不建立注單
     */
    #[Group('rules/vendor-aigamingsw#R-13')]
    public function test_deduct_rejects_insufficient_balance()
    {
        $this->member->update(['balance' => '0.1000']);

        $response = $this->postJson("{$this->baseurl}/deduct", $this->makePostData());
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['errorCode' => 5]);
        $this->assertSame(0, AiGamingSwBetRecord::count());
        $this->assertSame('0.1000', Common::numAdd('0', (string) $this->member->balance, 4));
    }

    /**
     * 情境：簽名錯誤（deduct 串接 amount/transactionId/betTime/gameTypeName）
     * 預期：回錯誤碼 4，不扣款
     */
    #[Group('rules/vendor-aigamingsw#R-5')]
    public function test_deduct_rejects_invalid_signature()
    {
        $this->member->update(['balance' => '100.0000']);
        $data = $this->makePostData();
        $data['amount'] = '50';  // 竄改金額使簽名失效

        $response = $this->postJson("{$this->baseurl}/deduct", $data);
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['errorCode' => 4]);
        $this->assertSame(0, AiGamingSwBetRecord::count());
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->member->balance, 4));
    }
}
