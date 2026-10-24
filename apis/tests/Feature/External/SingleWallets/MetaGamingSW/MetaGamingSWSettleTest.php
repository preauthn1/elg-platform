<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MetaGamingSW;

use App\Helpers\Common;
use App\Models\TransactionLog;
use App\Vendors\MetaGamingSW\OperatorStatusEnum;
use App\Vendors\Models\MetaGamingSWBetRecord;

/**
 * php artisan test tests/Feature/External/SingleWallets/MetaGamingSW/MetaGamingSWSettleTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/MetaGamingSW --compact
 */
class MetaGamingSWSettleTest extends MetaGamingSWTestCase
{
    private string $functionName = 'GameResult';

    private function makeBetRecord(array $overrides = []): MetaGamingSWBetRecord
    {
        return MetaGamingSWBetRecord::create(array_merge([
            'vendor_info_id' => $this->vendorInfo->id,
            'member_id' => $this->member->id,
            'type' => 'N',
            'round_id' => '438664',
            'bet_id' => '438665',
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
            'ResultId' => '438666',
            'BetId' => '438665',
            'RoundId' => '438664',
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
     * 情境：Bet 已處理（餘額已扣），首次收到 GameResult
     * 預期：加回 Payout、更新紀錄 status=1 及 win_lose、回傳 OK 及正確新舊餘額
     */
    public function test_settle_credits_payout_to_member()
    {
        $postData = $this->makePostData();
        $platformBet = Common::numDiv($postData['BetAmount'], $this->vendorInfo->rate, 4);
        $platformPayout = Common::numDiv($postData['Payout'], $this->vendorInfo->rate, 4);
        $platformWinLose = Common::numDiv($postData['WinLose'], $this->vendorInfo->rate, 4);

        // 模擬 Bet 已處理後的狀態：餘額已扣注金額
        $balanceAfterBet = Common::numSub('100.0000', $platformBet, 4);
        $this->member->update(['balance' => $balanceAfterBet]);
        $this->makeBetRecord();

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = MetaGamingSWBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'OldBalance' => Common::numMul($balanceAfterBet, $this->vendorInfo->rate, 4),
            'NewBalance' => Common::numMul(
                Common::numAdd($balanceAfterBet, $platformPayout, 4),
                $this->vendorInfo->rate,
                4
            ),
        ]);

        $this->assertEquals(1, $betRecord->status);
        $this->assertEquals($platformWinLose, $betRecord->win_lose);
        $this->assertEquals(Common::numAdd($platformWinLose, $platformBet, 4), $betRecord->win);
        $this->assertEquals($postData['ResultId'], $betRecord->reference_no);
    }

    /**
     * 情境：玩家輸局，Payout=0、WinLose 為負值
     * 預期：餘額不增加、紀錄 status=1、win_lose 更新為負值
     */
    public function test_settle_zero_payout_on_lose()
    {
        $walletBalance = '100.0000';
        $this->member->update(['balance' => $walletBalance]);
        $this->makeBetRecord();

        $postData = $this->makePostData(['Payout' => 0.0, 'WinLose' => -10.0]);
        $platformWinLose = Common::numDiv(-10.0, $this->vendorInfo->rate, 4);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = MetaGamingSWBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'OldBalance' => Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
            'NewBalance' => Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
        ]);

        $this->assertEquals(1, $betRecord->status);
        $this->assertEquals($platformWinLose, $betRecord->win_lose);
        $this->assertEquals($walletBalance, $this->member->balance);
    }

    /**
     * 情境：相同下注單重複收到 GameResult（已結算），對照 MTG UAT 案例（20000077739/20000077740）
     * 預期：回傳 900409 Duplicate Transaction，不重複入帳，資料庫無新增 TransactionLog
     */
    public function test_settle_returns_duplicate_transaction_when_already_settled()
    {
        $walletBalance = '100.0000';
        $this->member->update(['balance' => $walletBalance]);
        $this->makeBetRecord(['status' => 1]);

        $postData = $this->makePostData();

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['Status' => OperatorStatusEnum::DUPLICATE_TRANSACTION->value]);

        $this->assertEquals(1, MetaGamingSWBetRecord::count());
        $this->assertEquals(0, TransactionLog::count());
        $this->assertEquals($walletBalance, $this->member->balance);
    }

    /**
     * 情境：GameResult 到達時找不到對應的下注紀錄
     * 預期：回傳 BET_TRANSACTION_NOT_FOUND
     */
    public function test_settle_returns_not_found_when_bet_does_not_exist()
    {
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND->value,
        ]);
    }

    /**
     * 情境：對應的下注紀錄已被 Rollback（status=2）
     * 預期：回傳 BET_TRANSACTION_NOT_FOUND
     */
    public function test_settle_returns_not_found_when_bet_is_rollbacked()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->makeBetRecord(['status' => 2]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND->value,
        ]);
    }

    /**
     * 情境：請求攜帶錯誤的 Signature
     * 預期：回傳 INVALID_SIGNATURE，不建立任何交易日誌
     */
    public function test_settle_returns_invalid_signature_for_wrong_signature()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->makeBetRecord();

        $postData = $this->makePostData();
        $postData['Signature'] = 'invalid_signature';

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
        $this->assertEquals(0, TransactionLog::count());
    }

    /**
     * 情境：缺少文件必填欄位 ResultId（且簽名依缺漏資料重算）
     * 預期：回傳 INCOMING_REQUEST_INFO_INCOMPLETE
     */
    public function test_settle_returns_incoming_request_info_incomplete_when_result_id_is_missing()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->makeBetRecord();

        $postData = $this->makePostData();
        unset($postData['ResultId']);
        $this->regenerateSignature($this->functionName, $postData);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INCOMING_REQUEST_INFO_INCOMPLETE->value,
        ]);
    }
}
