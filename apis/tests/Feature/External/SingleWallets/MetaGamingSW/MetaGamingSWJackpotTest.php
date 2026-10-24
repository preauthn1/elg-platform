<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MetaGamingSW;

use App\Helpers\Common;
use App\Vendors\MetaGamingSW\OperatorStatusEnum;
use App\Vendors\Models\MetaGamingSWBetRecord;

/**
 * php artisan test tests/Feature/External/SingleWallets/MetaGamingSW/MetaGamingSWJackpotTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/MetaGamingSW --compact
 */
class MetaGamingSWJackpotTest extends MetaGamingSWTestCase
{
    private string $functionName = 'Jackpot';

    private function makePostData(array $overrides = []): array
    {
        $data = array_merge($this->basePostData(), [
            'TranId' => '998877',
            'JackpotId' => 'JP001',
            'Payout' => 500.0,
            'ExchangeRate' => 1.0,
            'TranDateTime' => '2024-09-30 14:16:32',
            'Result' => 'Minor Jackpot',
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-09-30T22:16:32.000+08:00',
        ], $overrides);
        $this->regenerateSignature($this->functionName, $data);

        return $data;
    }

    /**
     * 情境：首次收到 Jackpot 通知
     * 預期：將 Payout 加至餘額、建立 type=J status=1 的紀錄（jackpot 欄位有值，win/win_lose 為 0）、回傳 OK 及正確新舊餘額
     */
    public function test_jackpot_credits_payout_and_creates_record()
    {
        $walletBalance = '100.0000';
        $this->member->update(['balance' => $walletBalance]);

        $postData = $this->makePostData();
        $platformPayout = Common::numDiv($postData['Payout'], $this->vendorInfo->rate, 4);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = MetaGamingSWBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'OldBalance' => Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
            'NewBalance' => Common::numMul(
                Common::numAdd($walletBalance, $platformPayout, 4),
                $this->vendorInfo->rate,
                4
            ),
        ]);

        $this->assertEquals(Common::numAdd($walletBalance, $platformPayout, 4), $this->member->balance);
        $this->assertEquals(1, MetaGamingSWBetRecord::count());
        $this->assertEquals('J', $betRecord->type);
        $this->assertEquals($postData['TranId'], $betRecord->reference_no);
        $this->assertEquals(0, $betRecord->win);
        $this->assertEquals(0, $betRecord->win_lose);
        $this->assertEquals($platformPayout, $betRecord->jackpot);
        $this->assertEquals(1, $betRecord->status);
    }

    /**
     * 情境：相同 TranId 重複送出 Jackpot
     * 預期：回傳 DUPLICATE_TRANSACTION，資料庫僅保留一筆紀錄
     */
    public function test_jackpot_returns_duplicate_transaction_for_same_tran_id()
    {
        $walletBalance = '100.0000';
        $this->member->update(['balance' => $walletBalance]);
        $postData = $this->makePostData();
        $platformPayout = Common::numDiv($postData['Payout'], $this->vendorInfo->rate, 4);

        $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::DUPLICATE_TRANSACTION->value,
        ]);
        $this->assertEquals(1, MetaGamingSWBetRecord::count());
        $this->assertEquals(Common::numAdd($walletBalance, $platformPayout, 4), $this->member->balance);
    }

    /**
     * 情境：不同 TranId 重複使用同一個 JackpotId 類型代碼（對照 MTG UAT 實際請求：JackpotId "UUSJ"）
     * 預期：JackpotId 非逐筆唯一鍵，兩次皆應成功入帳，不可因 bet_id 誤設唯一鍵而回傳 900500
     */
    public function test_jackpot_succeeds_when_jackpot_id_reused_across_different_tran_ids()
    {
        $walletBalance = '100.0000';
        $this->member->update(['balance' => $walletBalance]);

        $firstPostData = array_merge($this->makePostData([
            'TranId' => '20000077719',
            'JackpotId' => 'UUSJ',
            'Payout' => 100.00,
            'ExchangeRate' => 100.00,
            'Result' => 'Christmas reward',
        ]));
        $secondPostData = $this->makePostData([
            'TranId' => '20000077748',
            'JackpotId' => 'UUSJ',
            'Payout' => 100.00,
            'ExchangeRate' => 100.00,
            'Result' => 'Christmas reward',
        ]);

        $firstResponse = $this->postJson("{$this->baseurl}/{$this->functionName}", $firstPostData);
        $secondResponse = $this->postJson("{$this->baseurl}/{$this->functionName}", $secondPostData);
        $this->member->refresh();

        $firstResponse->assertStatus(200)->assertJson(['Status' => OperatorStatusEnum::OK->value]);
        $secondResponse->assertStatus(200)->assertJson(['Status' => OperatorStatusEnum::OK->value]);

        $this->assertEquals(2, MetaGamingSWBetRecord::where('bet_id', 'UUSJ')->count());
        $platformPayout = Common::numDiv(100.00, $this->vendorInfo->rate, 4);
        $this->assertEquals(
            Common::numAdd($walletBalance, Common::numMul($platformPayout, 2, 4), 4),
            $this->member->balance
        );
    }

    /**
     * 情境：請求攜帶錯誤的 Signature
     * 預期：回傳 INVALID_SIGNATURE，不建立任何紀錄
     */
    public function test_jackpot_returns_invalid_signature_for_wrong_signature()
    {
        $this->member->update(['balance' => '100.0000']);

        $postData = $this->makePostData();
        $postData['Signature'] = 'invalid_signature';

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
        $this->assertEquals(0, MetaGamingSWBetRecord::count());
    }
}
