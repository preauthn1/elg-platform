<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\DstPlaySW;

use App\Helpers\Common;
use App\Vendors\Models\DstPlaySwBetRecord;

/**
 * php artisan test tests/Feature/External/SingleWallets/DstPlaySW/GameResultTest.php --compact
 */
class GameResultTest extends DstPlaySWTestCase
{
    private function makePostData(array $overrides = []): array
    {
        return $this->signedPostData(array_merge([
            'user_id' => $this->username,
            'game_round_id' => '32759',
            'game_code' => 'TEST',
            'bet_amount' => '5',
            'win_amount' => '-5',
            'log_id' => '8347830',
        ], $overrides));
    }

    /**
     * 情境：餘額足夠，正常下注結算
     * 預期：扣除 bet_amount 並加回 win_amount（此處為輸的情境，win_amount 為負），建立注單
     */
    public function test_game_result_deducts_bet_and_credits_win_amount()
    {
        $this->member->update(['balance' => '100.0000']);

        $response = $this->postJson("{$this->baseurl}/gameResult", $this->makePostData());
        $betRecord = DstPlaySwBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'code' => 0,
            'msg' => 'Success.',
            'beginBalance' => 100.0,
            'endBalance' => 95.0,
        ]);

        $this->assertSame('95.0000', Common::numAdd('0', (string) $this->member->balance, 4));
        $this->assertSame(1, DstPlaySwBetRecord::count());
        $this->assertSame('bet', $betRecord->type);
        $this->assertSame('5.0000', Common::numAdd('0', (string) $betRecord->bet, 4));
        $this->assertSame('-5.0000', Common::numAdd('0', (string) $betRecord->win_lose, 4));
        $this->assertSame('8347830', $betRecord->reference_no);
        $this->assertSame('32759', $betRecord->bet_id);
    }

    /**
     * 情境：vendor_info.rate 非 1:1（例如 1:1000）
     * 預期：收到的 bet_amount/win_amount 要除以 rate 換算成平台單位存注單，
     * 回傳的 beginBalance/endBalance 要乘回 rate 換算成廠商幣值單位
     */
    public function test_game_result_converts_amounts_by_rate_when_not_one_to_one()
    {
        $this->vendorInfo->update(['rate' => 1000]);
        $this->member->update(['balance' => '100.0000']);

        $response = $this->postJson("{$this->baseurl}/gameResult", $this->makePostData([
            'bet_amount' => '5000',
            'win_amount' => '-5000',
        ]));
        $betRecord = DstPlaySwBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'code' => 0,
            'msg' => 'Success.',
            'beginBalance' => 100000.0,
            'endBalance' => 95000.0,
        ]);

        $this->assertSame('95.0000', Common::numAdd('0', (string) $this->member->balance, 4));
        $this->assertSame('5.0000', Common::numAdd('0', (string) $betRecord->bet, 4));
        $this->assertSame('-5.0000', Common::numAdd('0', (string) $betRecord->win_lose, 4));
    }

    /**
     * 情境：相同 log_id 重新送出
     * 預期：不重複扣款，僅回傳目前餘額，資料庫仍只有一筆注單
     */
    public function test_game_result_is_idempotent_for_same_log_id()
    {
        $this->member->update(['balance' => '100.0000']);

        $this->postJson("{$this->baseurl}/gameResult", $this->makePostData());
        $response = $this->postJson("{$this->baseurl}/gameResult", $this->makePostData());
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['code' => 0, 'msg' => 'Success.']);
        $this->assertSame(1, DstPlaySwBetRecord::count());
        $this->assertSame('95.0000', Common::numAdd('0', (string) $this->member->balance, 4));
    }

    /**
     * 情境：餘額不足以支付 bet_amount
     * 預期：回傳錯誤代碼 103，不建立注單
     */
    public function test_game_result_returns_insufficient_balance_error()
    {
        $this->member->update(['balance' => '1.0000']);

        $response = $this->postJson("{$this->baseurl}/gameResult", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'code' => 103,
            'msg' => 'Insufficient Balance.',
        ]);
        $this->assertSame(0, DstPlaySwBetRecord::count());
    }

    /**
     * 情境：遊戲帳號停用
     * 預期：回傳 107 Unauthorized request，不建立注單
     */
    public function test_game_result_returns_unauthorized_when_account_disabled()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->account->update(['status' => 0]);

        $response = $this->postJson("{$this->baseurl}/gameResult", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'code' => 107,
            'msg' => 'Unauthorized request.',
        ]);
        $this->assertSame(0, DstPlaySwBetRecord::count());
    }

    /**
     * 情境：簽名錯誤
     * 預期：回傳 107 Unauthorized request，不建立注單
     */
    public function test_game_result_returns_unauthorized_for_wrong_signature()
    {
        $this->member->update(['balance' => '100.0000']);

        $postData = $this->makePostData();
        $postData['sign'] = 'invalid_signature';

        $response = $this->postJson("{$this->baseurl}/gameResult", $postData);

        $response->assertStatus(200)->assertJson([
            'code' => 107,
            'msg' => 'Unauthorized request.',
        ]);
        $this->assertSame(0, DstPlaySwBetRecord::count());
    }
}
