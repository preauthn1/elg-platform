<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MetaGamingSW;

use App\Helpers\Common;
use App\Models\TransactionLog;
use App\Vendors\MetaGamingSW\OperatorStatusEnum;
use App\Vendors\Models\MetaGamingSWBetRecord;

/**
 * php artisan test tests/Feature/External/SingleWallets/MetaGamingSW/MetaGamingSWBetTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/MetaGamingSW --compact
 */
class MetaGamingSWBetTest extends MetaGamingSWTestCase
{
    private string $functionName = 'Bet';

    private function makePostData(array $overrides = []): array
    {
        $data = array_merge($this->basePostData(), [
            'RoundId' => '438664',
            'BetId' => '438665',
            'GameCode' => '10000',
            'GameType' => 'Slots',
            'BetAmount' => 10.0,
            'ExchangeRate' => 1.0,
            'TranDateTime' => '2024-09-30 14:16:32',
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-09-30T22:16:32.000+08:00',
        ], $overrides);
        $this->regenerateSignature($this->functionName, $data);

        return $data;
    }

    /**
     * 情境：餘額足夠，首次下注
     * 預期：從餘額扣除注金、建立 type=N status=0 的紀錄、建立 TransactionLog、回傳 OK 及正確新舊餘額
     */
    public function test_bet_deducts_balance_and_creates_record()
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $postData = $this->makePostData();
        $platformBet = Common::numDiv($postData['BetAmount'], $this->vendorInfo->rate, 4);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = MetaGamingSWBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'OldBalance' => Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
            'NewBalance' => Common::numMul(Common::numSub($walletBalance, $platformBet, 4), $this->vendorInfo->rate, 4),
        ]);

        $this->assertEquals(Common::numSub($walletBalance, $platformBet, 4), $this->member->balance);
        $this->assertEquals(1, MetaGamingSWBetRecord::count());
        $this->assertEquals(1, TransactionLog::count());

        $this->assertEquals($postData['BetId'], $betRecord->bet_id);
        $this->assertEquals($platformBet, $betRecord->bet);
        $this->assertEquals(0, $betRecord->win);
        $this->assertEquals(Common::numMul($platformBet, -1, 4), $betRecord->win_lose);
        $this->assertEquals(0, $betRecord->status);
        $this->assertEquals('N', $betRecord->type);
    }

    /**
     * 情境：廠商以未加引號的 JSON 數字送出 RoundId/BetId（實際線上請求即為此格式，非字串）
     * 預期：仍視為合法請求，成功建立紀錄並扣款，而非誤判 INCOMING_REQUEST_INFO_INCOMPLETE
     */
    public function test_bet_accepts_round_id_and_bet_id_as_json_integers()
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $postData = $this->makePostData(['RoundId' => 532261898, 'BetId' => 532261897]);
        $platformBet = Common::numDiv($postData['BetAmount'], $this->vendorInfo->rate, 4);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = MetaGamingSWBetRecord::first();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
        ]);
        $this->assertEquals(1, MetaGamingSWBetRecord::count());
        $this->assertEquals((string) $postData['BetId'], $betRecord->bet_id);
        $this->assertEquals($platformBet, $betRecord->bet);
    }

    /**
     * 情境：相同 BetId 重複送出 Bet
     * 預期：回傳 DUPLICATE_TRANSACTION，資料庫僅保留一筆紀錄
     */
    public function test_bet_returns_duplicate_transaction_for_same_bet_id()
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);
        $postData = $this->makePostData();
        $platformBet = Common::numDiv($postData['BetAmount'], $this->vendorInfo->rate, 4);

        $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::DUPLICATE_TRANSACTION->value,
        ]);
        $this->assertEquals(1, MetaGamingSWBetRecord::count());
        $this->assertEquals(Common::numSub($walletBalance, $platformBet, 4), $this->member->balance);
    }

    /**
     * 情境：會員餘額為 0
     * 預期：回傳 INSUFFICIENT_BALANCE，不建立任何紀錄與交易日誌
     */
    public function test_bet_returns_insufficient_balance_when_balance_is_zero()
    {
        $this->member->update(['balance' => '0.0000']);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INSUFFICIENT_BALANCE->value,
        ]);
        $this->assertEquals(0, MetaGamingSWBetRecord::count());
        $this->assertEquals(0, TransactionLog::count());
    }

    /**
     * 情境：遊戲帳號狀態為停用
     * 預期：回傳 PLAYER_INACTIVE，不建立任何紀錄
     */
    public function test_bet_returns_player_inactive_when_account_disabled()
    {
        $this->member->update(['balance' => '1000.0000']);
        $this->account->update(['status' => 0]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::PLAYER_INACTIVE->value,
        ]);
        $this->assertEquals(0, MetaGamingSWBetRecord::count());
    }

    /**
     * 情境：PlayerId 不存在於系統
     * 預期：回傳 INVALID_PLAYER
     */
    public function test_bet_returns_invalid_player_for_unknown_player()
    {
        $postData = $this->makePostData(['PlayerId' => 'unknown_player']);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INVALID_PLAYER->value,
        ]);
    }

    /**
     * 情境：請求攜帶錯誤的 Signature
     * 預期：回傳 INVALID_SIGNATURE，不建立任何紀錄
     */
    public function test_bet_returns_invalid_signature_for_wrong_signature()
    {
        $postData = $this->makePostData();
        $postData['Signature'] = 'invalid_signature';

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
        $this->assertEquals(0, MetaGamingSWBetRecord::count());
    }

    /**
     * 情境：請求帶入不正確的 AuthToken
     * 預期：回傳 INTERNAL_SERVER_ERROR，且不建立任何紀錄與交易日誌
     */
    public function test_bet_returns_internal_server_error_when_auth_token_mismatch()
    {
        $this->account->update(['session_token' => 'valid_auth_token']);

        $postData = $this->makePostData();
        $postData['AuthToken'] = 'wrong_auth_token';
        $this->regenerateSignature($this->functionName, $postData);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INTERNAL_SERVER_ERROR->value,
        ]);
        $this->assertEquals(0, MetaGamingSWBetRecord::count());
        $this->assertEquals(0, TransactionLog::count());
    }

    /**
     * 情境：缺少文件必填欄位 BetId（且簽名依缺漏資料重算）
     * 預期：回傳 INCOMING_REQUEST_INFO_INCOMPLETE，且不建立任何紀錄與交易日誌
     */
    public function test_bet_returns_incoming_request_info_incomplete_when_bet_id_is_missing()
    {
        $postData = $this->makePostData();
        unset($postData['BetId']);
        $this->regenerateSignature($this->functionName, $postData);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INCOMING_REQUEST_INFO_INCOMPLETE->value,
        ]);
        $this->assertEquals(0, MetaGamingSWBetRecord::count());
        $this->assertEquals(0, TransactionLog::count());
    }
}
