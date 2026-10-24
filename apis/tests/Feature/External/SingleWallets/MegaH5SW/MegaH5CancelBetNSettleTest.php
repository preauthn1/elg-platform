<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MegaH5SW;

use App\Vendors\MegaH5SW\OperatorStatusEnum;
use App\Vendors\Models\MegaH5SWBetRecord;

/**
 * php artisan test tests/Feature/External/SingleWallets/MegaH5SW/MegaH5CancelBetNSettleTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/MegaH5SW --compact
 */
class MegaH5CancelBetNSettleTest extends MegaH5TestCase
{
    private string $functionName = 'CancelBetNSettle';

    private function makePostData(array $overrides = []): array
    {
        $data = array_merge($this->basePostData(), [
            'ResultId' => 438666,
            'BetId' => 438665,
            'RoundId' => 438664,
            'GameCode' => '10000',
            'GameType' => 'Slots',
            'GameName' => 'Game 001',
            'ResultType' => 0,
            'BetAmount' => 10.0,
            'ValidBetAmount' => 10.0,
            'Payout' => 20.0,
            'WinLose' => 10.0,
            'ExchangeRate' => 1.0,
            'TranDateTime' => '2024-09-30 14:16:32',
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-09-30T22:16:32.000+08:00',
            'RoundType' => 0,
        ], $overrides);
        $this->regenerateSignature($this->functionName, $data);

        return $data;
    }

    /**
     * 情境：對應的 BetNSettle 不存在（無既有紀錄）
     * 預期：回傳 CANCEL_BET_N_SETTLE_REJECTED，不建立任何紀錄
     */
    public function test_cancel_bet_n_settle_returns_rejected_when_bet_n_settle_was_not_processed()
    {
        $walletBalance = '100.0000';
        $this->member->update(['balance' => $walletBalance]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::CANCEL_BET_N_SETTLE_REJECTED->value,
        ]);

        $this->assertEquals($walletBalance, $this->member->balance);
        $this->assertEquals(0, MegaH5SWBetRecord::count());
    }

    /**
     * 情境：BetNSettle 已處理完畢（status=1 的紀錄已存在）
     * 預期：回傳 OK，餘額不變、資料庫仍只有一筆紀錄（冪等，不重複入帳）
     */
    public function test_cancel_bet_n_settle_is_idempotent_when_bet_n_settle_already_processed()
    {
        $walletBalance = '100.0000';
        $this->member->update(['balance' => $walletBalance]);

        // 模擬 BetNSettle 已處理過——紀錄已存在
        MegaH5SWBetRecord::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'member_id' => $this->member->id,
            'type' => 'N',
            'round_id' => 438664,
            'bet_id' => 438665,
            'reference_no' => '438666',
            'username' => $this->acctId,
            'game_code' => '10000',
            'bet' => '10.0000',
            'valid_bet' => '10.0000',
            'win' => '20.0000',
            'win_lose' => '10.0000',
            'rolling_chip' => '0.0000',
            'info' => '[]',
            'bet_time' => '2024-09-30 14:16:32',
            'payout_time' => '2024-09-30 14:16:32',
            'status' => 1,
        ]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
        ]);

        // 餘額不變——沒有重複結算
        $this->assertEquals($walletBalance, $this->member->balance);
        $this->assertEquals(1, MegaH5SWBetRecord::count());
    }

    /**
     * 情境：BetId 相同但 ResultId 不同（非同一筆 BetNSettle 交易）
     * 預期：回傳 CANCEL_BET_N_SETTLE_REJECTED
     */
    public function test_cancel_bet_n_settle_returns_rejected_when_result_id_does_not_match_existing_transaction()
    {
        $this->member->update(['balance' => '100.0000']);

        MegaH5SWBetRecord::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'member_id' => $this->member->id,
            'type' => 'N',
            'bet_id' => 438665,
            'reference_no' => '999999',
            'username' => $this->acctId,
            'game_code' => '10000',
            'bet' => '10.0000',
            'valid_bet' => '10.0000',
            'win' => '20.0000',
            'win_lose' => '10.0000',
            'rolling_chip' => '0.0000',
            'info' => '[]',
            'bet_time' => '2024-09-30 14:16:32',
            'payout_time' => '2024-09-30 14:16:32',
            'status' => 1,
        ]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::CANCEL_BET_N_SETTLE_REJECTED->value,
        ]);
        $this->assertEquals(1, MegaH5SWBetRecord::count());
    }

    /**
     * 情境：請求攜帶錯誤的 Signature
     * 預期：回傳 INVALID_SIGNATURE，不建立任何紀錄
     */
    public function test_cancel_bet_n_settle_returns_invalid_signature_for_wrong_signature()
    {
        $this->member->update(['balance' => '100.0000']);

        $postData = $this->makePostData();
        $postData['Signature'] = 'invalid_signature';

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
        $this->assertEquals(0, MegaH5SWBetRecord::count());
    }

    /**
     * 情境：遊戲帳號狀態為停用，且沒有既有紀錄
     * 預期：cancelBetNSettle() 本身不檢查帳號狀態，直接因找不到紀錄回傳 CANCEL_BET_N_SETTLE_REJECTED
     */
    public function test_cancel_bet_n_settle_returns_rejected_when_account_disabled_and_no_record_exists()
    {
        $this->member->update(['balance' => '1000.0000']);
        $this->account->update(['status' => 0]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::CANCEL_BET_N_SETTLE_REJECTED->value,
        ]);
        $this->assertEquals(0, MegaH5SWBetRecord::count());
    }

    /**
     * 情境：缺少文件必填欄位 ResultId（且簽名依缺漏資料重算）
     * 預期：回傳 INCOMING_REQUEST_INFO_INCOMPLETE
     */
    public function test_cancel_bet_n_settle_returns_incoming_request_info_incomplete_when_result_id_is_missing()
    {
        $this->member->update(['balance' => '100.0000']);

        $postData = $this->makePostData();
        unset($postData['ResultId']);
        $this->regenerateSignature($this->functionName, $postData);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INCOMING_REQUEST_INFO_INCOMPLETE->value,
        ]);
    }
}
