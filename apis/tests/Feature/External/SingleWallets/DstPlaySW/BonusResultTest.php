<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\DstPlaySW;

use App\Helpers\Common;
use App\Vendors\Models\DstPlaySwBetRecord;

/**
 * php artisan test tests/Feature/External/SingleWallets/DstPlaySW/BonusResultTest.php --compact
 */
class BonusResultTest extends DstPlaySWTestCase
{
    private function makePostData(array $overrides = []): array
    {
        return $this->signedPostData(array_merge([
            'user_id' => $this->username,
            'game_round_id' => '32759',
            'game_code' => 'TEST',
            'bet_amount' => '5',
            'win_amount' => '12.5',
            'log_id' => '8347830',
            'bonus_id' => '345346457',
        ], $overrides));
    }

    /**
     * 情境：免費旋轉派彩，win_amount 全額入帳，不扣款（bonusResult 不消耗 bet_amount）
     */
    public function test_bonus_result_credits_win_amount_without_deducting_bet()
    {
        $this->member->update(['balance' => '100.0000']);

        $response = $this->postJson("{$this->baseurl}/bonusResult", $this->makePostData());
        $betRecord = DstPlaySwBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'code' => 0,
            'msg' => 'Success.',
            'beginBalance' => 100.0,
            'endBalance' => 112.5,
        ]);

        $this->assertSame('112.5000', Common::numAdd('0', (string) $this->member->balance, 4));
        $this->assertSame('bonus', $betRecord->type);
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->bet, 4));
        $this->assertSame('12.5000', Common::numAdd('0', (string) $betRecord->win, 4));
    }

    /**
     * 情境：相同 log_id 重新送出
     * 預期：不重複加彩，僅回傳目前餘額
     */
    public function test_bonus_result_is_idempotent_for_same_log_id()
    {
        $this->member->update(['balance' => '100.0000']);

        $this->postJson("{$this->baseurl}/bonusResult", $this->makePostData());
        $response = $this->postJson("{$this->baseurl}/bonusResult", $this->makePostData());
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['code' => 0, 'msg' => 'Success.']);
        $this->assertSame(1, DstPlaySwBetRecord::count());
        $this->assertSame('112.5000', Common::numAdd('0', (string) $this->member->balance, 4));
    }
}
