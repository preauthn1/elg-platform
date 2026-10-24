<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\AiGamingSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\Models\AiGamingSwBetRecord;
use PHPUnit\Framework\Attributes\Group;

/**
 * payload 取自 2026-08-31 dev 實測 callback（tx 459099，dancedancedance bonus game 派彩 15.5；
 * gameCode 改為測試遊戲）。實測特性：bonus 的 transactionId 是獨立新單（非 deduct 的單號）、
 * amount 恆等於 payoutAmount（gameInfo.betAmount=0，無額外投注），僅入 payoutAmount。
 *
 * php artisan test tests/Feature/External/SingleWallets/AiGamingSW/BonusTest.php --compact
 */
class BonusTest extends AiGamingSWTestCase
{
    private function makePostData(array $overrides = []): array
    {
        return $this->signedPostData('bonus', array_merge([
            'amount' => '15.5',
            'payoutAmount' => '15.5',
            'transactionId' => '459099',
            'resultType' => 'WIN',
            'resultTime' => '2026-08-31T17:40:41.834518212',
            'isFirst' => true,
            'isEnd' => false,
            'gameCode' => 'TEST',
            'gameTypeName' => 'TEST',
            'gameInfo' => '{}',
        ], $overrides));
    }

    /**
     * 情境：bonus game 派彩（獨立新單號，事前無對應 deduct）
     * 預期：只加款 payoutAmount（amount 為鏡射值不參與運算），建立 bet=0 的已結算注單與 TBR
     */
    #[Group('rules/vendor-aigamingsw#R-18')]
    public function test_bonus_creates_standalone_record_and_credits_payout_only()
    {
        $this->member->update(['balance' => '100.0000']);

        $response = $this->postJson("{$this->baseurl}/bonus", $this->makePostData());
        $betRecord = AiGamingSwBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['errorCode' => 0, 'balance' => 115.5]);

        $this->assertSame('115.5000', Common::numAdd('0', (string) $this->member->balance, 4));
        $this->assertSame(1, AiGamingSwBetRecord::count());
        $this->assertSame('459099', $betRecord->bet_id);
        $this->assertSame(1, (int) $betRecord->status);
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->valid_bet, 4));
        $this->assertSame('15.5000', Common::numAdd('0', (string) $betRecord->win, 4));
        $this->assertSame('15.5000', Common::numAdd('0', (string) $betRecord->win_lose, 4));
        $this->assertSame(1, TotalBetRecord::count());
    }

    /**
     * 情境：原投注（deduct+settle）後收到 bonus——完整實測序列 459092 → 459099
     * 預期：bonus 以獨立注單入帳，不影響原單金額
     */
    #[Group('rules/vendor-aigamingsw#R-18')]
    public function test_bonus_does_not_touch_original_bet_record()
    {
        $this->member->update(['balance' => '100.0000']);
        $this->placeDeduct();  // tx 459092, bet 0.4

        $response = $this->postJson("{$this->baseurl}/bonus", $this->makePostData());
        $original = AiGamingSwBetRecord::where('bet_id', '459092')->first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['errorCode' => 0]);
        $this->assertSame(2, AiGamingSwBetRecord::count());
        $this->assertSame('0.4000', Common::numAdd('0', (string) $original->bet, 4));
        $this->assertSame('115.1000', Common::numAdd('0', (string) $this->member->balance, 4));
    }

    /**
     * 情境：free spin 未中獎（實測 tx 460970，amount=0 / payoutAmount=0 / LOSE）
     * 預期：回成功與餘額，但不建注單、不建 TBR（避免全 0 空單）
     */
    #[Group('rules/vendor-aigamingsw#R-19')]
    public function test_bonus_with_zero_payout_is_acknowledged_without_record()
    {
        $this->member->update(['balance' => '100.0000']);

        $response = $this->postJson("{$this->baseurl}/bonus", $this->makePostData([
            'amount' => '0',
            'payoutAmount' => '0',
            'transactionId' => '460970',
            'resultType' => 'LOSE',
        ]));
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['errorCode' => 0, 'balance' => 100.0]);
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->member->balance, 4));
        $this->assertSame(0, AiGamingSwBetRecord::count());
        $this->assertSame(0, TotalBetRecord::count());
    }

    /**
     * 情境：相同 transactionId 重複 bonus（重送）
     * 預期：回錯誤碼 5003，不重複派彩
     */
    #[Group('rules/vendor-aigamingsw#R-20')]
    public function test_bonus_rejects_duplicate_transaction_id()
    {
        $this->member->update(['balance' => '100.0000']);

        $this->postJson("{$this->baseurl}/bonus", $this->makePostData());
        $response = $this->postJson("{$this->baseurl}/bonus", $this->makePostData());
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['errorCode' => 5003]);
        $this->assertSame(1, AiGamingSwBetRecord::count());
        $this->assertSame('115.5000', Common::numAdd('0', (string) $this->member->balance, 4));
    }
}
