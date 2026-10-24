<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\AiGamingSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\Models\AiGamingSwBetRecord;
use PHPUnit\Framework\Attributes\Group;

/**
 * php artisan test tests/Feature/External/SingleWallets/AiGamingSW/CashBonusTest.php --compact
 */
class CashBonusTest extends AiGamingSWTestCase
{
    private function makePostData(array $overrides = []): array
    {
        return $this->signedPostData('cashBonus', array_merge([
            'amount' => '0',
            'payoutAmount' => '8',
            'transactionId' => 'cb-1001',
            'resultType' => 'WIN',
            'resultTime' => '2026-08-31T10:05:00',
            'gameCode' => 'TEST',
            'gameTypeName' => 'TEST',
            'gameInfo' => '{}',
        ], $overrides));
    }

    /**
     * 情境：純派彩（無對應投注，自帶獨立 transactionId）
     * 預期：金額記活動獎金欄（bonus）加款，不入 win / win_lose，建立已結算注單與 TBR
     */
    #[Group('rules/vendor-aigamingsw#R-23')]
    public function test_cash_bonus_credits_payout_as_bonus()
    {
        $this->member->update(['balance' => '100.0000']);

        $response = $this->postJson("{$this->baseurl}/cashBonus", $this->makePostData());
        $betRecord = AiGamingSwBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['errorCode' => 0, 'balance' => 108.0]);

        $this->assertSame('108.0000', Common::numAdd('0', (string) $this->member->balance, 4));
        $this->assertSame(1, (int) $betRecord->status);
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->win, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->win_lose, 4));
        $this->assertSame('8.0000', Common::numAdd('0', (string) $betRecord->bonus, 4));
        $this->assertSame(1, TotalBetRecord::count());
        $this->assertSame('8.0000', Common::numAdd('0', (string) TotalBetRecord::first()->bonus, 4));
    }

    /**
     * 情境：相同 transactionId 重複 cashBonus
     * 預期：回錯誤碼 5003，不重複派彩
     */
    #[Group('rules/vendor-aigamingsw#R-24')]
    public function test_cash_bonus_rejects_duplicate_transaction_id()
    {
        $this->member->update(['balance' => '100.0000']);

        $this->postJson("{$this->baseurl}/cashBonus", $this->makePostData());
        $response = $this->postJson("{$this->baseurl}/cashBonus", $this->makePostData());
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['errorCode' => 5003]);
        $this->assertSame('108.0000', Common::numAdd('0', (string) $this->member->balance, 4));
        $this->assertSame(1, AiGamingSwBetRecord::count());
    }

    /**
     * 情境：payoutAmount 為 0（規格要求必須 > 0）
     * 預期：回錯誤碼 7，不建立注單
     */
    #[Group('rules/vendor-aigamingsw#R-24')]
    public function test_cash_bonus_rejects_zero_payout()
    {
        $response = $this->postJson("{$this->baseurl}/cashBonus", $this->makePostData([
            'payoutAmount' => '0',
        ]));

        $response->assertStatus(200)->assertJson(['errorCode' => 7]);
        $this->assertSame(0, AiGamingSwBetRecord::count());
    }
}
