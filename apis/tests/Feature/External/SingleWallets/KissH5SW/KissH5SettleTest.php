<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\KissH5SW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Models\TotalBetRecordTransactionLog;
use App\Models\TransactionLog;
use App\Vendors\KissH5SW\OperatorStatusEnum;
use App\Vendors\Models\KissH5SWBetRecord;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * php artisan test tests/Feature/External/SingleWallets/KissH5SW/KissH5SettleTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/KissH5SW --compact
 */
class KissH5SettleTest extends KissH5TestCase
{
    private string $functionName = 'GameResult';

    private function makeBetRecord(array $overrides = []): KissH5SWBetRecord
    {
        return KissH5SWBetRecord::create(array_merge([
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

    #[TestDox('Bet 已處理（餘額已扣），首次收到 GameResult：加回 Payout、更新紀錄 status=1 及 win_lose、回傳 OK 及正確新舊餘額')]
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
        $betRecord = KissH5SWBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'OldBalance' => (float) Common::numMul($balanceAfterBet, $this->vendorInfo->rate, 4),
            'NewBalance' => (float) Common::numMul(
                Common::numAdd($balanceAfterBet, $platformPayout, 4),
                $this->vendorInfo->rate,
                4
            ),
        ]);

        $this->assertEquals(1, $betRecord->status);
        $this->assertEquals($platformWinLose, $betRecord->win_lose);
        $this->assertEquals(Common::numAdd($platformWinLose, $platformBet, 4), $betRecord->win);
        $this->assertEquals((string) $postData['ResultId'], $betRecord->reference_no);
    }

    #[TestDox('玩家輸局，Payout=0、WinLose 為負值：餘額不增加、紀錄 status=1、win_lose 更新為負值')]
    public function test_settle_zero_payout_on_lose()
    {
        $walletBalance = '100.0000';
        $this->member->update(['balance' => $walletBalance]);
        $this->makeBetRecord();

        $postData = $this->makePostData(['Payout' => 0.0, 'WinLose' => -10.0]);
        $platformWinLose = Common::numDiv(-10.0, $this->vendorInfo->rate, 4);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = KissH5SWBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'OldBalance' => (float) Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
            'NewBalance' => (float) Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
        ]);

        $this->assertEquals(1, $betRecord->status);
        $this->assertEquals($platformWinLose, $betRecord->win_lose);
        $this->assertEquals($walletBalance, $this->member->balance);
    }

    #[TestDox('完整 Bet → GameResult 輸局（Payout=0）流程：結算雖無真金變動，total_bet_record 仍關聯到投注那筆 transaction_log（SR14 detail 查得到輸錢注單）')]
    public function test_settle_relates_total_bet_record_to_bet_transaction_log_when_payout_is_zero()
    {
        $this->member->update(['balance' => '100.0000']);

        // 1) Bet：扣注金，建立 status=999（遊戲中）的投注 transaction_log；KissH5 此時不建 total_bet_record
        $this->placeBet();

        $this->assertEquals(1, TransactionLog::count(), 'bet 應建立 1 筆投注 transaction_log');
        $this->assertEquals(0, TotalBetRecord::count(), 'KissH5 於結算才建 total_bet_record');
        $betTransactionLog = TransactionLog::first();

        // 2) GameResult 輸局：Payout=0 使真金淨變動為 0，不會新增 transaction_log
        $settleData = $this->makePostData(['Payout' => 0.0, 'WinLose' => -10.0]);
        $this->postJson("{$this->baseurl}/{$this->functionName}", $settleData)
            ->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::OK->value]);

        $this->assertEquals(1, TransactionLog::count(), '輸局結算無真金變動，不應新增 transaction_log');
        $this->assertEquals(1, TotalBetRecord::count());

        // 核心：修正前結算取不到 transaction_log id（回傳 0）而跳過建關聯，輸錢注單永遠不會出現在 SR14 detail
        $this->assertDatabaseHas('total_bet_record_transaction_logs', [
            'transaction_log_id' => $betTransactionLog->id,
            'total_bet_record_id' => TotalBetRecord::first()->id,
        ]);
    }

    #[TestDox('全禮碼投注結算（Payout=0）：該回合未動用真金，total_bet_record 不得關聯到同 session 其他回合的現金 transaction_log')]
    public function test_settle_does_not_relate_rolling_chip_only_bet_to_cash_transaction_log()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->rollingChipWallet->update(['balance' => '0.0000']);

        // 1) 真金投注一筆（禮碼錢包為空），建立 status=999 的現金 transaction_log
        $this->placeBet();
        $cashTransactionLog = TransactionLog::first();
        $this->assertEquals(1, TransactionLog::count());

        // 2) 補滿禮碼後再投注一筆，全額由禮碼支付：真金錢包不動、不新增現金紀錄
        $this->rollingChipWallet->update(['balance' => '1000.0000']);
        $balanceBeforeChipBet = $this->member->fresh()->balance;

        $this->placeBet(['RoundId' => 438670, 'BetId' => 438671]);

        $this->assertEquals($balanceBeforeChipBet, $this->member->fresh()->balance, '全禮碼投注不應動到真金錢包');
        $this->assertEquals(1, TransactionLog::count(), '全禮碼投注不應新增現金紀錄');

        // 3) 該筆全禮碼注單結算輸局
        $settleData = $this->makePostData([
            'ResultId' => 438672,
            'BetId' => 438671,
            'RoundId' => 438670,
            'Payout' => 0.0,
            'WinLose' => -10.0,
        ]);
        $this->postJson("{$this->baseurl}/{$this->functionName}", $settleData)
            ->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::OK->value]);

        // 核心：整筆走禮碼、與現金無關，不可掛到前一回合的現金紀錄底下
        $chipBetRecord = KissH5SWBetRecord::where('bet_id', 438671)->first();
        $chipTotalBetRecord = TotalBetRecord::where('bet_record_id', $chipBetRecord->id)->first();

        $this->assertNotNull($chipTotalBetRecord, '結算仍應建立 total_bet_record');
        $this->assertDatabaseMissing('total_bet_record_transaction_logs', [
            'transaction_log_id' => $cashTransactionLog->id,
            'total_bet_record_id' => $chipTotalBetRecord->id,
        ]);
    }

    #[TestDox('當日首次遊玩即為全禮碼投注、輸局結算：全程無現金紀錄，total_bet_record 建立但無 transaction_log 可關聯')]
    public function test_settle_rolling_chip_only_bet_as_first_action_of_session()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->rollingChipWallet->update(['balance' => '1000.0000']);

        // 當日第一個動作就是全禮碼投注（此前無任何 transaction_log）
        $this->placeBet();

        $this->assertEquals('100.0000', $this->member->fresh()->balance, '真金錢包不應變動');
        $this->assertEquals(0, TransactionLog::count(), '全禮碼投注不應產生現金紀錄');

        $settleData = $this->makePostData(['Payout' => 0.0, 'WinLose' => -10.0]);
        $this->postJson("{$this->baseurl}/{$this->functionName}", $settleData)
            ->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::OK->value]);

        $this->assertEquals(0, TransactionLog::count(), '輸局派彩為 0，仍不應產生現金紀錄');
        $this->assertEquals(1, TotalBetRecord::count(), '結算仍建立 total_bet_record');
        $this->assertEquals(0, TotalBetRecordTransactionLog::count(), '無現金紀錄可關聯');
    }

    #[TestDox('當日首次遊玩即為全禮碼投注、贏局結算：派彩為真金，建立純派彩的 transaction_log 並關聯 total_bet_record')]
    public function test_settle_rolling_chip_only_bet_with_payout_creates_cash_log()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->rollingChipWallet->update(['balance' => '1000.0000']);

        $this->placeBet();
        $this->assertEquals(0, TransactionLog::count());

        // 派彩 20：投注由禮碼支付，派彩仍進真金錢包
        $settleData = $this->makePostData(['Payout' => 20.0, 'WinLose' => 10.0]);
        $this->postJson("{$this->baseurl}/{$this->functionName}", $settleData)
            ->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::OK->value]);

        $this->assertEquals(1, TransactionLog::count(), '派彩動到真金，應建立現金紀錄');
        $payoutLog = TransactionLog::first();

        $this->assertDatabaseHas('total_bet_record_transaction_logs', [
            'transaction_log_id' => $payoutLog->id,
            'total_bet_record_id' => TotalBetRecord::first()->id,
        ]);
    }

    private function placeBet(array $overrides = []): void
    {
        $betData = array_merge($this->basePostData(), [
            'RoundId' => 438664,
            'BetId' => 438665,
            'GameCode' => '10000',
            'GameType' => 'Slots',
            'BetAmount' => 10.0,
            'ExchangeRate' => 1.0,
            'TranDateTime' => '2024-09-30 14:16:32',
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-09-30T22:16:32.000+08:00',
        ], $overrides);
        $this->regenerateSignature('Bet', $betData);

        $this->postJson("{$this->baseurl}/Bet", $betData)
            ->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::OK->value]);
    }

    #[TestDox('相同下注單重複收到 GameResult（已結算）：兩次均回傳 OK，不重複入帳，資料庫無新增 TransactionLog')]
    public function test_settle_is_idempotent_when_already_settled()
    {
        $walletBalance = '100.0000';
        $this->member->update(['balance' => $walletBalance]);
        $this->makeBetRecord(['status' => 1]);

        $postData = $this->makePostData();

        $firstResponse = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $secondResponse = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $this->member->refresh();

        $firstResponse->assertStatus(200)->assertJson(['Status' => OperatorStatusEnum::OK->value]);
        $secondResponse->assertStatus(200)->assertJson(['Status' => OperatorStatusEnum::OK->value]);

        $this->assertEquals(1, KissH5SWBetRecord::count());
        $this->assertEquals(0, TransactionLog::count());
        $this->assertEquals($walletBalance, $this->member->balance);
    }

    #[TestDox('GameResult 到達時找不到對應的下注紀錄：回傳 BET_TRANSACTION_NOT_FOUND')]
    public function test_settle_returns_not_found_when_bet_does_not_exist()
    {
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND->value,
        ]);
    }

    #[TestDox('ResultId/BetId/RoundId 為大整數，且資料庫僅存在相鄰 BetId 的注單：不會誤命中相鄰注單，回傳 BET_TRANSACTION_NOT_FOUND')]
    public function test_settle_does_not_match_adjacent_record_when_ids_are_large_integers()
    {
        $this->member->update(['balance' => '100.0000']);

        $largeBetId = 70000003993882870;
        $this->makeBetRecord([
            'bet_id' => (string) ($largeBetId + 1),
            'round_id' => '70000003993882871',
            'reference_no' => '70000003993882921',
        ]);

        $postData = $this->makePostData([
            'ResultId' => 70000003993882920,
            'BetId' => $largeBetId,
            'RoundId' => 70000003993882870,
        ]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND->value,
        ]);

        $this->assertEquals(1, KissH5SWBetRecord::count());
        $this->assertEquals(0, TransactionLog::count());
        $this->assertSame('70000003993882871', KissH5SWBetRecord::first()->bet_id);
    }

    #[TestDox('對應的下注紀錄已被 Rollback（status=2）：回傳 BET_TRANSACTION_NOT_FOUND')]
    public function test_settle_returns_not_found_when_bet_is_rollbacked()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->makeBetRecord(['status' => 2]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND->value,
        ]);
    }

    #[TestDox('請求攜帶錯誤的 Signature：回傳 INVALID_SIGNATURE，不建立任何紀錄與交易日誌')]
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

    #[TestDox('缺少文件必填欄位 ResultId（且簽名依缺漏資料重算）：回傳 INCOMING_REQUEST_INFO_INCOMPLETE')]
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
