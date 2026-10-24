<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\KissH5SW;

use App\Helpers\Common;
use App\Vendors\KissH5SW\OperatorStatusEnum;
use App\Vendors\Models\KissH5SWBetRecord;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * php artisan test tests/Feature/External/SingleWallets/KissH5SW/KissH5JackpotTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/KissH5SW --compact
 */
class KissH5JackpotTest extends KissH5TestCase
{
    private string $functionName = 'Jackpot';

    private function makePostData(array $overrides = []): array
    {
        $data = array_merge($this->basePostData(), [
            'TranId' => '998877',
            'JackpotId' => 'JP_MEGA_001',
            'JackpotName' => 'Mega Jackpot',
            'JackpotType' => 1,
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

    #[TestDox('首次收到 Jackpot 通知：將 Payout 加至餘額、建立 type=J status=1 的紀錄（jackpot 欄位有值，win/win_lose 為 0）、回傳 OK 及正確新舊餘額')]
    public function test_jackpot_credits_payout_and_creates_record()
    {
        $walletBalance = '100.0000';
        $this->member->update(['balance' => $walletBalance]);

        $postData = $this->makePostData();
        $platformPayout = Common::numDiv($postData['Payout'], $this->vendorInfo->rate, 4);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = KissH5SWBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'OldBalance' => (float) Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
            'NewBalance' => (float) Common::numMul(
                Common::numAdd($walletBalance, $platformPayout, 4),
                $this->vendorInfo->rate,
                4
            ),
        ]);

        $this->assertEquals(Common::numAdd($walletBalance, $platformPayout, 4), $this->member->balance);
        $this->assertEquals(1, KissH5SWBetRecord::count());
        $this->assertEquals('J', $betRecord->type);
        $this->assertEquals($postData['TranId'], $betRecord->reference_no);
        $this->assertEquals(0, $betRecord->win);
        $this->assertEquals(0, $betRecord->win_lose);
        $this->assertEquals($platformPayout, $betRecord->jackpot);
        $this->assertEquals(1, $betRecord->status);
    }

    #[TestDox('相同 TranId 重複送出 Jackpot：回傳 DUPLICATE_TRANSACTION，資料庫僅保留一筆紀錄')]
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
        $this->assertEquals(1, KissH5SWBetRecord::count());
        $this->assertEquals(Common::numAdd($walletBalance, $platformPayout, 4), $this->member->balance);
    }

    #[TestDox('請求攜帶錯誤的 Signature：回傳 INVALID_SIGNATURE，不建立任何紀錄')]
    public function test_jackpot_returns_invalid_signature_for_wrong_signature()
    {
        $this->member->update(['balance' => '100.0000']);

        $postData = $this->makePostData();
        $postData['Signature'] = 'invalid_signature';

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
        $this->assertEquals(0, KissH5SWBetRecord::count());
    }
}
