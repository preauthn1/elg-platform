<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Slot4DSW;

use App\Helpers\Common;
use App\Models\TransactionLog;
use App\Vendors\Models\Slot4dswBetRecord;
use App\Vendors\Slot4DSW\OperatorStatusEnum;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * php artisan test tests/Feature/External/SingleWallets/Slot4DSW/Slot4DRollbackTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/Slot4DSW --compact
 */
class Slot4DRollbackTest extends Slot4DTestCase
{
    private string $functionName = 'Rollback';

    private function makeBetRecord(array $overrides = []): Slot4dswBetRecord
    {
        return Slot4dswBetRecord::create(array_merge([
            'vendor_info_id' => $this->vendorInfo->id,
            'member_id' => $this->member->id,
            'type' => 'N',
            'round_id' => 438664,
            'bet_id' => 438665,
            'reference_no' => '438665',
            'username' => $this->acctId,
            'game_code' => '10000',
            'bet' => '10.0000',
            'valid_bet' => '10.0000',
            'win' => '0.0000',
            'win_lose' => '-10.0000',
            'rolling_chip' => '0.0000',
            'info' => '[]',
            'bet_time' => '2024-09-30 14:16:32',
            'payout_time' => null,
            'status' => 0,
        ], $overrides));
    }

    private function makePostData(array $overrides = []): array
    {
        $data = array_merge($this->basePostData(), [
            'RoundId' => 438664,
            'BetId' => 438665,
            'GameCode' => '10000',
            'GameType' => 'Slots',
            'BetAmount' => 10.0,
            'ExchangeRate' => 1.0,
            'TranDateTime' => '2024-09-30 14:16:32',
            'RollbackType' => 1,
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-09-30T22:16:32.000+08:00',
        ], $overrides);
        $this->regenerateSignature($this->functionName, $data);

        return $data;
    }

    #[TestDox('找到 status=0 的未結算下注紀錄：退回注金至餘額、紀錄 status 更新為 2、bet 與 win_lose 歸零、回傳 OK 及正確新舊餘額')]
    public function test_rollback_refunds_balance_and_marks_record()
    {
        $walletBalance = '90.0000';
        $this->member->update(['balance' => $walletBalance]);
        $this->makeBetRecord();

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());
        $betRecord = Slot4dswBetRecord::first();
        $this->member->refresh();

        $bet = Common::numDiv(10.0, $this->vendorInfo->rate, 4);
        $expectedNewBalance = Common::numAdd($walletBalance, $bet, 4);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'OldBalance' => (float) Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
            'NewBalance' => (float) Common::numMul($expectedNewBalance, $this->vendorInfo->rate, 4),
        ]);

        $this->assertEquals(2, $betRecord->status);
        $this->assertEquals(0, $betRecord->bet);
        $this->assertEquals(0, $betRecord->win_lose);
        $this->assertEquals($expectedNewBalance, $this->member->balance);
    }

    #[TestDox('下注紀錄已被 Rollback（status=2），重複收到 Rollback：回傳 OK，不重複退款（冪等）')]
    public function test_rollback_is_idempotent_when_already_rollbacked()
    {
        $walletBalance = '100.0000';
        $this->member->update(['balance' => $walletBalance]);
        $this->makeBetRecord(['status' => 2]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'OldBalance' => (float) Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
            'NewBalance' => (float) Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
        ]);
        $this->assertEquals($walletBalance, $this->member->balance);
        $this->assertEquals(0, TransactionLog::count());
    }

    #[TestDox('Rollback 到達時找不到對應的下注紀錄：回傳 BET_TRANSACTION_NOT_FOUND')]
    public function test_rollback_returns_not_found_when_bet_does_not_exist()
    {
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND->value,
        ]);
    }

    #[TestDox('請求攜帶錯誤的 Signature：回傳 INVALID_SIGNATURE，不建立任何紀錄與交易日誌')]
    public function test_rollback_returns_invalid_signature_for_wrong_signature()
    {
        $this->member->update(['balance' => '90.0000']);
        $this->makeBetRecord();

        $postData = $this->makePostData();
        $postData['Signature'] = 'invalid_signature';

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
        $this->assertEquals(0, TransactionLog::count());
    }

    #[TestDox('對應的下注紀錄已結算（status=1）：回傳 BET_TRANSACTION_NOT_FOUND，終止重試（文件 5.5：已結算訂單屬異常）')]
    public function test_rollback_returns_not_found_when_bet_is_already_settled()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->makeBetRecord(['status' => 1]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND->value,
        ]);
    }
}
