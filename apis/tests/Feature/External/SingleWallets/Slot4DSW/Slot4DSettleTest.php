<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Slot4DSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Models\TransactionLog;
use App\Vendors\Models\Slot4dswBetRecord;
use App\Vendors\Slot4DSW\OperatorStatusEnum;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * php artisan test tests/Feature/External/SingleWallets/Slot4DSW/Slot4DSettleTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/Slot4DSW --compact
 */
class Slot4DSettleTest extends Slot4DTestCase
{
    private string $functionName = 'GameResult';

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
        $betRecord = Slot4dswBetRecord::first();
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
        $betRecord = Slot4dswBetRecord::first();
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

    #[TestDox('完整 Bet → GameResult 輸局（Payout=0）流程：結算才建 total_bet_record，且仍關聯到投注那筆 transaction_log（SR14 detail 查得到輸錢注單）')]
    public function test_settle_relates_total_bet_record_to_bet_transaction_log_when_payout_is_zero()
    {
        $this->member->update(['balance' => '100.0000']);

        // 1) Bet：扣注金並建立 status=999（遊戲中）的投注 transaction_log，此時不建 total_bet_record
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
        ]);
        $this->regenerateSignature('Bet', $betData);

        $this->postJson("{$this->baseurl}/Bet", $betData)
            ->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::OK->value]);

        $this->assertEquals(1, TransactionLog::count(), 'bet 應建立 1 筆投注 transaction_log');
        $this->assertEquals(0, TotalBetRecord::count(), '結算才建 total_bet_record');
        $betTransactionLog = TransactionLog::first();

        // 2) GameResult 輸局：Payout=0 使真金淨變動為 0，不會新增 transaction_log
        $settleData = $this->makePostData(['Payout' => 0.0, 'WinLose' => -10.0]);
        $this->postJson("{$this->baseurl}/{$this->functionName}", $settleData)
            ->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::OK->value]);

        $this->assertEquals(1, TransactionLog::count(), '輸局結算無真金變動，不應新增 transaction_log');
        $this->assertEquals(1, TotalBetRecord::count());

        $this->assertDatabaseHas('total_bet_record_transaction_logs', [
            'transaction_log_id' => $betTransactionLog->id,
            'total_bet_record_id' => TotalBetRecord::first()->id,
        ]);
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

        $this->assertEquals(1, Slot4dswBetRecord::count());
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

        $this->assertEquals(1, Slot4dswBetRecord::count());
        $this->assertEquals(0, TransactionLog::count());
        $this->assertSame('70000003993882871', Slot4dswBetRecord::first()->bet_id);
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
