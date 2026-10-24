<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\DstPlaySW;

use App\Helpers\Common;
use App\Vendors\Models\DstPlaySwBetRecord;

/**
 * php artisan test tests/Feature/External/SingleWallets/DstPlaySW/RefundTest.php --compact
 */
class RefundTest extends DstPlaySWTestCase
{
    private function placeBet(): void
    {
        $this->member->update(['balance' => '100.0000']);

        $this->postJson("{$this->baseurl}/gameResult", $this->signedPostData([
            'user_id' => $this->username,
            'game_round_id' => '32759',
            'game_code' => 'TEST',
            'bet_amount' => '5',
            'win_amount' => '-5',
            'log_id' => '8347830',
        ]));
    }

    /**
     * 情境：對已存在的 gameResult 執行退款
     * 預期：全額沖銷該筆注單（bet/win 歸零），餘額回復至下注前狀態，狀態標記為取消/回滾
     */
    public function test_refund_reverses_the_original_bet()
    {
        $this->placeBet();
        $this->member->refresh();
        $this->assertSame('95.0000', Common::numAdd('0', (string) $this->member->balance, 4));

        $response = $this->postJson("{$this->baseurl}/refund", $this->signedPostData([
            'user_id' => $this->username,
            'log_id' => '347584375349',
            'refund_log_id' => '8347830',
        ]));
        $betRecord = DstPlaySwBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['code' => 0, 'msg' => 'Success.']);
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->member->balance, 4));
        $this->assertSame(2, $betRecord->status);
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->win_lose, 4));
    }

    /**
     * 情境：refund_log_id 找不到對應的 gameResult
     * 預期：依原文仍回傳代碼 0，不影響餘額
     */
    public function test_refund_returns_success_when_refund_log_id_not_found()
    {
        $this->member->update(['balance' => '100.0000']);

        $response = $this->postJson("{$this->baseurl}/refund", $this->signedPostData([
            'user_id' => $this->username,
            'log_id' => '347584375349',
            'refund_log_id' => 'not_exist',
        ]));
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['code' => 0, 'msg' => 'Success.']);
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->member->balance, 4));
    }

    /**
     * 情境：相同 log_id 重複送出退款請求
     * 預期：只沖銷一次，第二次僅回傳成功不重複調整餘額
     */
    public function test_refund_is_idempotent_for_already_refunded_bet()
    {
        $this->placeBet();

        $this->postJson("{$this->baseurl}/refund", $this->signedPostData([
            'user_id' => $this->username,
            'log_id' => '347584375349',
            'refund_log_id' => '8347830',
        ]));
        $response = $this->postJson("{$this->baseurl}/refund", $this->signedPostData([
            'user_id' => $this->username,
            'log_id' => '347584375349',
            'refund_log_id' => '8347830',
        ]));
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['code' => 0, 'msg' => 'Success.']);
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->member->balance, 4));
    }
}
