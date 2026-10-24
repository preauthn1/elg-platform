<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\AiGamingSW;

use App\Helpers\Common;
use App\Vendors\Models\AiGamingSwBetRecord;
use PHPUnit\Framework\Attributes\Group;

/**
 * rollback 尚無實測樣本（deduct 成功不會觸發），欄位依 api.md；deduct 起手用實測 payload（tx 459092）
 *
 * php artisan test tests/Feature/External/SingleWallets/AiGamingSW/RollbackTest.php --compact
 */
class RollbackTest extends AiGamingSWTestCase
{
    private function makePostData(array $overrides = []): array
    {
        return $this->signedPostData('rollback', array_merge([
            'amount' => '0.4',
            'transactionId' => '459092',
            'gameCode' => 'TEST',
            'gameTypeName' => 'TEST',
            'gameInfo' => '{}',
        ], $overrides));
    }

    private function settleData(): array
    {
        return $this->signedPostData('settle', [
            'transactionId' => '459092',
            'payoutAmount' => '0.16',
            'resultType' => 'WIN',
            'resultTime' => '2026-08-31T17:39:21.687229933',
            'gameCode' => 'TEST',
            'gameTypeName' => 'TEST',
            'gameInfo' => '{}',
        ]);
    }

    /**
     * 情境：deduct 後下注失敗，廠商發 rollback 取消投注
     * 預期：本金退回、注單歸零轉取消（status=2）
     */
    #[Group('rules/vendor-aigamingsw#R-21')]
    public function test_rollback_refunds_deducted_stake()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->placeDeduct();

        $response = $this->postJson("{$this->baseurl}/rollback", $this->makePostData());
        $betRecord = AiGamingSwBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['errorCode' => 0, 'balance' => 100.0]);
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->member->balance, 4));
        $this->assertSame(2, (int) $betRecord->status);
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->valid_bet, 4));
    }

    /**
     * 情境：重複 rollback
     * 預期：回錯誤碼 5008（Bet Already Returned Stake），不重複退款
     */
    #[Group('rules/vendor-aigamingsw#R-22')]
    public function test_rollback_is_idempotent()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->placeDeduct();

        $this->postJson("{$this->baseurl}/rollback", $this->makePostData());
        $response = $this->postJson("{$this->baseurl}/rollback", $this->makePostData());
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['errorCode' => 5008]);
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->member->balance, 4));
    }

    /**
     * 情境：rollback 找不到對應注單
     * 預期：回錯誤碼 6（Bet not exists）
     */
    #[Group('rules/vendor-aigamingsw#R-22')]
    public function test_rollback_returns_error_when_bet_not_exists()
    {
        $response = $this->postJson("{$this->baseurl}/rollback", $this->makePostData());

        $response->assertStatus(200)->assertJson(['errorCode' => 6]);
    }

    /**
     * 情境：已結算注單收到 rollback
     * 預期：回錯誤碼 2001（Bet Already Settled），不沖銷
     */
    #[Group('rules/vendor-aigamingsw#R-22')]
    public function test_rollback_rejects_settled_bet()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->placeDeduct();
        $this->postJson("{$this->baseurl}/settle", $this->settleData());

        $response = $this->postJson("{$this->baseurl}/rollback", $this->makePostData());
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['errorCode' => 2001]);
        $this->assertSame('99.7600', Common::numAdd('0', (string) $this->member->balance, 4));
    }
}
