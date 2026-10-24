<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\AiGamingSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\Models\AiGamingSwBetRecord;
use PHPUnit\Framework\Attributes\Group;

/**
 * payload 取自 2026-08-31 dev 實測 callback（tx 459092 WIN 0.16 / tx 459098 LOSE 0；gameCode 改為測試遊戲）
 *
 * php artisan test tests/Feature/External/SingleWallets/AiGamingSW/SettleTest.php --compact
 */
class SettleTest extends AiGamingSWTestCase
{
    private function makePostData(array $overrides = []): array
    {
        return $this->signedPostData('settle', array_merge([
            'transactionId' => '459092',
            'payoutAmount' => '0.16',
            'resultType' => 'WIN',
            'resultTime' => '2026-08-31T17:39:21.687229933',
            'isEnd' => false,
            'hasBonus' => false,
            'gameCode' => 'TEST',
            'gameTypeName' => 'TEST',
            'gameInfo' => '{}',
        ], $overrides));
    }

    /**
     * 情境：中獎結算（payoutAmount 含本金；實測 isEnd 恆 false）
     * 預期：加回派彩、注單一律轉已結算、payout_time 換算 UTC、建立 TBR
     */
    #[Group('rules/vendor-aigamingsw#R-14')]
    #[Group('rules/vendor-aigamingsw#R-8')]
    public function test_settle_credits_payout_and_creates_total_bet_record()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->placeDeduct();

        $response = $this->postJson("{$this->baseurl}/settle", $this->makePostData());
        $betRecord = AiGamingSwBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'errorCode' => 0,
            'balance' => 99.76,
        ]);

        $this->assertSame('99.7600', Common::numAdd('0', (string) $this->member->balance, 4));
        // 實測 isEnd 恆為 false，settle 一次即鎖定已結算
        $this->assertSame(1, (int) $betRecord->status);
        $this->assertSame('0.1600', Common::numAdd('0', (string) $betRecord->win, 4));
        $this->assertSame('-0.2400', Common::numAdd('0', (string) $betRecord->win_lose, 4));
        // 實測 resultTime 為 UTC+8，落帳須換算 UTC
        $this->assertSame('2026-08-31 09:39:21', (string) $betRecord->payout_time);
        $this->assertSame(1, TotalBetRecord::count());
    }

    /**
     * 情境：輸錢結算（payoutAmount=0，實測 tx 459098 LOSE）
     * 預期：餘額不變、TBR 建立且關聯 transaction_log（SR14 現金紀錄 detail 依 pivot 反查）
     */
    #[Group('rules/vendor-aigamingsw#R-16')]
    public function test_settle_with_zero_payout_still_links_transaction_log()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->placeDeduct();

        $response = $this->postJson("{$this->baseurl}/settle", $this->makePostData([
            'payoutAmount' => '0',
            'resultType' => 'LOSE',
        ]));
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['errorCode' => 0, 'balance' => 99.6]);
        $this->assertSame('99.6000', Common::numAdd('0', (string) $this->member->balance, 4));
        $this->assertSame(1, TotalBetRecord::count());
        $this->assertDatabaseHas('total_bet_record_transaction_logs', [
            'total_bet_record_id' => TotalBetRecord::first()->id,
        ]);
    }

    /**
     * 情境：settle 找不到對應注單
     * 預期：回錯誤碼 6（Bet not exists）
     */
    #[Group('rules/vendor-aigamingsw#R-17')]
    public function test_settle_returns_error_when_bet_not_exists()
    {
        $response = $this->postJson("{$this->baseurl}/settle", $this->makePostData());

        $response->assertStatus(200)->assertJson(['errorCode' => 6]);
    }

    /**
     * 情境：已結算注單重複 settle（重送）
     * 預期：回錯誤碼 2001（Bet Already Settled，廠商據此停止重送），不重複派彩
     */
    #[Group('rules/vendor-aigamingsw#R-17')]
    public function test_settle_is_idempotent_for_settled_bet()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->placeDeduct();

        $this->postJson("{$this->baseurl}/settle", $this->makePostData());
        $response = $this->postJson("{$this->baseurl}/settle", $this->makePostData());
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['errorCode' => 2001]);
        $this->assertSame('99.7600', Common::numAdd('0', (string) $this->member->balance, 4));
    }

    /**
     * 情境：resultType=CANCEL（局取消，payoutAmount 退回本金）
     * 預期：本金退回、有效投注歸零不計流水
     */
    #[Group('rules/vendor-aigamingsw#R-15')]
    public function test_settle_cancel_refunds_stake_and_zeroes_valid_bet()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->placeDeduct();

        $response = $this->postJson("{$this->baseurl}/settle", $this->makePostData([
            'payoutAmount' => '0.4',
            'resultType' => 'CANCEL',
        ]));
        $betRecord = AiGamingSwBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['errorCode' => 0, 'balance' => 100.0]);
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->member->balance, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->valid_bet, 4));
    }
}
