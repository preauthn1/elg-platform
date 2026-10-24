<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MetaGamingSW;

use App\Helpers\Common;
use App\Models\TransactionLog;
use App\Vendors\MetaGamingSW\OperatorStatusEnum;
use App\Vendors\Models\MetaGamingSWBetRecord;

/**
 * php artisan test tests/Feature/External/SingleWallets/MetaGamingSW/MetaGamingSWRollbackTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/MetaGamingSW --compact
 */
class MetaGamingSWRollbackTest extends MetaGamingSWTestCase
{
    private string $functionName = 'Rollback';

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
            'RoundId' => '438664',
            'BetId' => '438665',
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

    /**
     * 情境：找到 status=0 的未結算下注紀錄
     * 預期：退回注金至餘額、紀錄 status 更新為 2、bet 與 win_lose 歸零、回傳 OK 及正確新舊餘額
     */
    public function test_rollback_refunds_balance_and_marks_record()
    {
        $walletBalance = '90.0000';
        $this->member->update(['balance' => $walletBalance]);
        $this->makeBetRecord();

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());
        $betRecord = MetaGamingSWBetRecord::first();
        $this->member->refresh();

        $bet = Common::numDiv(10.0, $this->vendorInfo->rate, 4);
        $expectedNewBalance = Common::numAdd($walletBalance, $bet, 4);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'OldBalance' => Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
            'NewBalance' => Common::numMul($expectedNewBalance, $this->vendorInfo->rate, 4),
        ]);

        $this->assertEquals(2, $betRecord->status);
        $this->assertEquals(0, $betRecord->bet);
        $this->assertEquals(0, $betRecord->win_lose);
        $this->assertEquals($expectedNewBalance, $this->member->balance);
    }

    /**
     * 情境：下注紀錄已被 Rollback（status=2），重複收到 Rollback，對照 MTG UAT 案例（BetId 20000077744）
     * 預期：回傳 900409 Duplicate Transaction，不重複退款
     */
    public function test_rollback_returns_duplicate_transaction_when_already_rollbacked()
    {
        $walletBalance = '100.0000';
        $this->member->update(['balance' => $walletBalance]);
        $this->makeBetRecord(['status' => 2]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['Status' => OperatorStatusEnum::DUPLICATE_TRANSACTION->value]);
        $this->assertEquals($walletBalance, $this->member->balance);
        $this->assertEquals(0, TransactionLog::count());
    }

    /**
     * 情境：Rollback 到達時找不到對應的下注紀錄
     * 預期：回傳 BET_TRANSACTION_NOT_FOUND
     */
    public function test_rollback_returns_not_found_when_bet_does_not_exist()
    {
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND->value,
        ]);
    }

    /**
     * 情境：請求攜帶錯誤的 Signature
     * 預期：回傳 INVALID_SIGNATURE，不建立任何交易日誌
     */
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

    /**
     * 情境：對應的下注紀錄已結算（status=1）
     * 預期：回傳 BET_TRANSACTION_NOT_FOUND，終止重試
     */
    public function test_rollback_returns_not_found_when_bet_is_already_settled()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->makeBetRecord(['status' => 1]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND->value,
        ]);
    }

    /**
     * 情境：廠商以未加引號的 JSON 數字送出 RoundId/BetId（實際線上請求即為此格式，非字串）
     * 預期：仍視為合法請求並成功退款，而非誤判 INCOMING_REQUEST_INFO_INCOMPLETE
     */
    public function test_rollback_accepts_round_id_and_bet_id_as_json_integers()
    {
        $walletBalance = '90.0000';
        $this->member->update(['balance' => $walletBalance]);
        $this->makeBetRecord(['round_id' => '532580435', 'bet_id' => '532580435']);

        $postData = $this->makePostData(['RoundId' => 532580435, 'BetId' => 532580435]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = MetaGamingSWBetRecord::first();

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
        ]);
        $this->assertEquals(2, $betRecord->status);
    }
}
