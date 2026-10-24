<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Spadegaming;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Models\TransactionLog;
use App\Vendors\Models\SpadegamingswBetRecord;
use App\Vendors\SpadegamingSW\Enums\TransferTypeEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 紅包獎勵 API 測試（api header = transfer，type = BONUS）
 *
 * 驗證遊戲商主動發放紅包獎勵時的入帳邏輯：
 * Bonus 為無對應下注的純入款，金額直接入真金錢包（不影響禮碼錢包）。
 *
 * - 成功：入帳至真金錢包，建立 TotalBetRecord（bet=0，win_lose=入帳金額）
 * - 冪等性：相同 transferId 重送時直接回傳成功，不重複入帳
 *
 * 成功後預期狀態：
 * - SpadegamingswBetRecord +1（type = BONUS，reference_bet_id 與 bet_id 相同）
 * - TransactionLog +1
 * - TotalBetRecord +1（直接 create，不走 createOrUpdate）
 */
class BonusTest extends SpadegamingSWTestCase
{
    private $bonusTransferId = '20240801142953555117852_type_4';

    private $bonusPostData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bonusPostData = [
            'acctId' => $this->acctId,
            'merchantCode' => $this->merchantCode,
            'serialNo' => $this->defaultSerialNo,
            'transferId' => $this->bonusTransferId,
            'type' => TransferTypeEnum::BONUS,
            'currency' => 'MYR',
            'gameCode' => $this->game->game_type,
            'channel' => 'Mobile',
            'amount' => 3000,
            'transferTime' => date('Y-m-d H:i:s'),
        ];
    }

    /** R-24：收到紅利回調時，不需參考編號，紅利額直接加入會員錢包，產生一筆紅利注單並建立總注單。 */
    #[Group('rules/vendor-spadegamingsw#R-24')]
    #[TestDox('紅利入真金錢包，產生 BONUS 注單並建立總注單')]
    public function test_bonus_success()
    {
        // Arrange
        $postData = $this->bonusPostData;

        $walletBalance = 100.01;
        $this->wallet->update([
            'balance' => $walletBalance,
        ]);

        $rollingChips = 1000.05;
        $this->rollingChipWallet->update([
            'balance' => $rollingChips,
        ]);

        $totalBalance = Common::numAdd($walletBalance, $rollingChips);
        $platformAmount = Common::numDiv($postData['amount'], $this->vendorInfo->rate, 4);

        $expectedBalance = Common::numAdd($totalBalance, $platformAmount);
        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);
        $betRecord = SpadegamingswBetRecord::where('bet_id', $this->bonusTransferId)
            ->whereNotNull('reference_bet_id')
            ->first();

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'msg' => 'success',
                'merchantCode' => $this->merchantCode,
                'serialNo' => $this->defaultSerialNo,
                'transferId' => $postData['transferId'],
                'acctId' => $this->acctId,
                'balance' => Common::numMul($expectedBalance, $this->vendorInfo->rate, 4),
            ]);

        $this->assertNotEmpty($betRecord);
        $this->assertNotNull($betRecord->infos);
        $this->assertEquals(0, $betRecord->bet);
        $this->assertEquals($platformAmount, $betRecord->win_lose);
        $this->assertEquals(0, $betRecord->rolling_chips);
        $this->assertEquals(TransferTypeEnum::BONUS, $betRecord->type);

        // Records
        $this->assertEquals(1, SpadegamingswBetRecord::count());
        $this->assertEquals(1, TransactionLog::count());
        $this->assertEquals(1, TotalBetRecord::count());
    }

    /** R-25：同一個轉帳編號的紅利重複送達時，回當前餘額與成功碼 0，不重複加款。 */
    #[Group('rules/vendor-spadegamingsw#R-25')]
    #[TestDox('同一 transferId 的紅利重送直接回成功，不重複入帳')]
    public function test_bonus_failed_duplicate()
    {
        // Arrange
        $postData = $this->bonusPostData;

        $walletBalance = 100.01;
        $this->wallet->update([
            'balance' => $walletBalance,
        ]);

        $rollingChips = 1000.05;
        $this->rollingChipWallet->update([
            'balance' => $rollingChips,
        ]);

        $totalBalance = Common::numAdd($walletBalance, $rollingChips);
        $platformAmount = Common::numDiv($postData['amount'], $this->vendorInfo->rate, 4);

        $expectedBalance = Common::numAdd($totalBalance, $platformAmount);
        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);

        $betRecord = SpadegamingswBetRecord::where('bet_id', $this->bonusTransferId)
            ->whereNotNull('reference_bet_id')
            ->first();

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'msg' => 'success',
                'merchantCode' => $this->merchantCode,
                'serialNo' => $this->defaultSerialNo,
                'transferId' => $postData['transferId'],
                'acctId' => $this->acctId,
                'balance' => Common::numMul($expectedBalance, $this->vendorInfo->rate, 4),
            ]);

        $this->assertNotEmpty($betRecord);
        $this->assertNotNull($betRecord->infos);
        $this->assertEquals(0, $betRecord->bet);
        $this->assertEquals($platformAmount, $betRecord->win_lose);
        $this->assertEquals(0, $betRecord->rolling_chips);
        $this->assertEquals(TransferTypeEnum::BONUS, $betRecord->type);

        // Records
        $this->assertEquals(1, SpadegamingswBetRecord::count());
        $this->assertEquals(1, TransactionLog::count());
        $this->assertEquals(1, TotalBetRecord::count());
    }
}
