<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MegaH5SW;

use App\Helpers\Common;
use App\Vendors\MegaH5SW\OperatorStatusEnum;
use App\Vendors\Models\MegaH5SWBetRecord;

/**
 * php artisan test tests/Feature/External/SingleWallets/MegaH5SW/MegaH5BetNSettleTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/MegaH5SW --compact
 */
class MegaH5BetNSettleTest extends MegaH5TestCase
{
    private string $functionName = 'BetNSettle';

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
     * 情境：餘額足夠，首次收到 BetNSettle
     * 預期：扣除注金並加回彩金（淨變化 = WinLose）、建立 type=N status=1 的紀錄、回傳 OK 及正確新舊餘額
     */
    public function test_bet_n_settle_deducts_bet_credits_payout_and_creates_record()
    {
        $walletBalance = '100.0000';
        $this->member->update(['balance' => $walletBalance]);

        $postData = $this->makePostData();
        $bet = Common::numDiv($postData['BetAmount'], $this->vendorInfo->rate, 4);
        $winLose = Common::numDiv($postData['WinLose'], $this->vendorInfo->rate, 4);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = MegaH5SWBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'OldBalance' => (float) Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
            'NewBalance' => (float) Common::numMul(
                Common::numAdd($walletBalance, $winLose, 4),
                $this->vendorInfo->rate,
                4
            ),
        ]);

        $this->assertEquals(Common::numAdd($walletBalance, $winLose, 4), $this->member->balance);
        $this->assertEquals(1, MegaH5SWBetRecord::count());
        $this->assertEquals('N', $betRecord->type);
        $this->assertEquals(1, $betRecord->status);
        $this->assertEquals($bet, $betRecord->bet);
        $this->assertEquals($winLose, $betRecord->win_lose);
        $this->assertEquals(Common::numAdd($winLose, $bet, 4), $betRecord->win);
        $this->assertEquals((string) $postData['ResultId'], $betRecord->reference_no);
        $this->assertEquals($postData['BetId'], $betRecord->bet_id);
        $this->assertEquals($postData['RoundId'], $betRecord->round_id);
    }

    /**
     * 情境：相同 BetId 重複送出 BetNSettle
     * 預期：回傳 DUPLICATE_TRANSACTION，資料庫僅保留一筆紀錄
     */
    public function test_bet_n_settle_returns_duplicate_transaction_for_same_bet_id()
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);
        $postData = $this->makePostData();
        $winLose = Common::numDiv($postData['WinLose'], $this->vendorInfo->rate, 4);

        $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::DUPLICATE_TRANSACTION->value,
        ]);
        $this->assertEquals(1, MegaH5SWBetRecord::count());
        $this->assertEquals(Common::numAdd($walletBalance, $winLose, 4), $this->member->balance);
    }

    /**
     * 情境：會員餘額為 0
     * 預期：回傳 INSUFFICIENT_BALANCE，不建立任何紀錄
     */
    public function test_bet_n_settle_returns_insufficient_balance_when_balance_is_zero()
    {
        $this->member->update(['balance' => '0.0000']);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INSUFFICIENT_BALANCE->value,
        ]);
        $this->assertEquals(0, MegaH5SWBetRecord::count());
    }

    /**
     * 情境：遊戲帳號狀態為停用
     * 預期：回傳 PLAYER_INACTIVE，不建立任何紀錄
     */
    public function test_bet_n_settle_returns_player_inactive_when_account_disabled()
    {
        $this->member->update(['balance' => '1000.0000']);
        $this->account->update(['status' => 0]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::PLAYER_INACTIVE->value,
        ]);
        $this->assertEquals(0, MegaH5SWBetRecord::count());
    }

    /**
     * 情境：請求攜帶錯誤的 Signature
     * 預期：回傳 INVALID_SIGNATURE，不建立任何紀錄
     */
    public function test_bet_n_settle_returns_invalid_signature_for_wrong_signature()
    {
        $this->member->update(['balance' => '1000.0000']);

        $postData = $this->makePostData();
        $postData['Signature'] = 'invalid_signature';

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
        $this->assertEquals(0, MegaH5SWBetRecord::count());
    }

    /**
     * 情境：請求帶入不正確的 AuthToken
     * 預期：回傳 INTERNAL_SERVER_ERROR，且不建立任何紀錄
     */
    public function test_bet_n_settle_returns_internal_server_error_when_auth_token_mismatch()
    {
        $this->account->update(['auth_token' => 'valid_auth_token']);
        $this->member->update(['balance' => '1000.0000']);

        $postData = $this->makePostData();
        $postData['AuthToken'] = 'wrong_auth_token';
        $this->regenerateSignature($this->functionName, $postData);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INTERNAL_SERVER_ERROR->value,
        ]);
        $this->assertEquals(0, MegaH5SWBetRecord::count());
    }

    /**
     * 情境：缺少文件必填欄位 ResultId（且簽名依缺漏資料重算）
     * 預期：回傳 INCOMING_REQUEST_INFO_INCOMPLETE
     */
    public function test_bet_n_settle_returns_incoming_request_info_incomplete_when_result_id_is_missing()
    {
        $this->member->update(['balance' => '1000.0000']);

        $postData = $this->makePostData();
        unset($postData['ResultId']);
        $this->regenerateSignature($this->functionName, $postData);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INCOMING_REQUEST_INFO_INCOMPLETE->value,
        ]);
        $this->assertEquals(0, MegaH5SWBetRecord::count());
    }
}
