<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Spadegaming;

use App\Helpers\Common;
use App\Models\AgentGame;
use App\Models\Game;
use App\Models\TotalBetRecord;
use App\Models\TransactionLog;
use App\Models\VendorInfoGame;
use App\Vendors\Models\SpadegamingswBetRecord;
use App\Vendors\SpadegamingSW\Enums\ReturnStatusEnum;
use App\Vendors\SpadegamingSW\Enums\TransferTypeEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 下注 API 測試（api header = transfer，type = PLACE_BET）
 *
 * 驗證玩家下注時的餘額扣款邏輯與 BetRecord 建立：
 * - 成功：優先消耗禮碼錢包，不足再扣真金錢包；回傳剩餘餘額（已乘匯率）
 * - 棋牌遊戲（gameCode 以 C- 開頭）：先到付費，下注時即建立 BetRecord
 * - 冪等性：相同 transferId 重送時直接回傳成功，不重複扣款
 *
 * 成功後預期狀態：
 * - SpadegamingswBetRecord +1（type = PLACE_BET）
 * - TransactionLog +1（記錄真金錢包扣款）
 * - TotalBetRecord 不建立（需等 Payout 才建立）
 */
class PlaceBetTest extends SpadegamingSWTestCase
{
    private $defaultPostData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaultPostData = [
            'transferId' => '20240801142953555117852',
            'type' => TransferTypeEnum::PLACE_BET,
            'currency' => 'MYR',
            'gameCode' => $this->game->game_type,
            'channel' => 'Mobile',
            'amount' => 300,
            'serialNo' => $this->defaultSerialNo,
            'acctId' => $this->acctId,
            'merchantCode' => $this->merchantCode,
            'transferTime' => date('Y-m-d H:i:s'),
        ];
    }

    /** R-9：收到下注回調時，依匯率換算的投注額從會員錢包扣除（禮碼先扣、真金補足），產生一筆下注注單，並回扣款後的餘額。 */
    #[Group('rules/vendor-spadegamingsw#R-9')]
    #[TestDox('下注先扣禮碼再扣真金，產生 PLACE_BET 注單並回扣款後餘額')]
    public function test_place_bet_success()
    {
        // Arrange
        $postData = $this->defaultPostData;

        $walletBalance = 1000.05;
        $this->wallet->update([
            'balance' => $walletBalance,
        ]);

        $rollingChips = 100.01;
        $this->rollingChipWallet->update([
            'balance' => $rollingChips,
        ]);

        $totalBalance = Common::numAdd($walletBalance, $rollingChips);
        $platformAmount = Common::numDiv($postData['amount'], $this->vendorInfo->rate, 4);

        $expectedBalance = Common::numSub($totalBalance, $platformAmount);
        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);
        $betRecord = SpadegamingswBetRecord::where('bet_id', $postData['transferId'])
            ->whereNull('reference_bet_id')
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
        $this->assertEquals($platformAmount, $betRecord->bet);
        $this->assertEquals($platformAmount, $betRecord->valid_bet);
        $this->assertEquals(Common::numMul($platformAmount, -1, 4), $betRecord->win_lose);
        $this->assertEquals($rollingChips, $betRecord->rolling_chips); // Use all.
        $this->assertEquals(TransferTypeEnum::PLACE_BET, $betRecord->type);

        // rolling chip first, then wallet
        $this->rollingChipWallet->refresh();
        $this->wallet->refresh();
        $this->assertEquals(0, $this->rollingChipWallet->balance);
        $this->assertEquals(
            Common::numSub($walletBalance, Common::numSub($platformAmount, $rollingChips), 4),
            $this->wallet->balance
        );

        $this->assertEquals(1, SpadegamingswBetRecord::count());
        $this->assertEquals(1, TransactionLog::count());
        $this->assertEquals(0, TotalBetRecord::count());
    }

    /** R-9：收到下注回調時，依匯率換算的投注額從會員錢包扣除（禮碼先扣、真金補足），產生一筆下注注單，並回扣款後的餘額。 */
    #[Group('rules/vendor-spadegamingsw#R-9')]
    #[TestDox('棋牌類先到付費遊戲下注同樣扣款並建注單')]
    public function test_place_bet_success_prepaid_game()
    {
        // Arrange
        $postData = $this->defaultPostData;

        $postData['gameCode'] = 'C-TF01';
        $prepaidGame = Game::factory()->create([
            'vendor_id' => $this->vendorInfo->vendor_id,
            'game_type' => $postData['gameCode'],
            'name' => 'test',
            'image' => '',
            'valid_bet_rate' => $this->vendor->valid_bet_rate,
        ]);
        VendorInfoGame::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'game_id' => $prepaidGame->id,
            'status' => 1,
        ]);
        AgentGame::create([
            'agent_id' => $this->agent->id,
            'game_id' => $prepaidGame->id,
            'status' => 1,
        ]);

        $walletBalance = 1000.05;
        $this->wallet->update([
            'balance' => $walletBalance,
        ]);

        $rollingChips = 100.01;
        $this->rollingChipWallet->update([
            'balance' => $rollingChips,
        ]);

        $totalBalance = Common::numAdd($walletBalance, $rollingChips);
        $platformAmount = Common::numDiv($postData['amount'], $this->vendorInfo->rate, 4);

        $expectedBalance = Common::numSub($totalBalance, $platformAmount);
        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);
        $betRecord = SpadegamingswBetRecord::where('bet_id', $postData['transferId'])
            ->whereNull('reference_bet_id')
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
        $this->assertEquals($platformAmount, $betRecord->bet);
        $this->assertEquals($platformAmount, $betRecord->valid_bet);
        $this->assertEquals(Common::numMul($platformAmount, -1, 4), $betRecord->win_lose);
        $this->assertEquals($rollingChips, $betRecord->rolling_chips); // Use all.
        $this->assertEquals(TransferTypeEnum::PLACE_BET, $betRecord->type);

        // rolling chip first, then wallet
        $this->rollingChipWallet->refresh();
        $this->wallet->refresh();
        $this->assertEquals(0, $this->rollingChipWallet->balance);
        $this->assertEquals(
            Common::numSub($walletBalance, Common::numSub($platformAmount, $rollingChips), 4),
            $this->wallet->balance
        );

        $this->assertEquals(1, SpadegamingswBetRecord::count());
        $this->assertEquals(1, TransactionLog::count());
        $this->assertEquals(0, TotalBetRecord::count());
    }

    /** R-12：同一個轉帳編號（transferId）的下注重複送達時，回當前餘額與成功碼 0，不再扣款、不再建注單。 */
    #[Group('rules/vendor-spadegamingsw#R-12')]
    #[TestDox('同一 transferId 的下注重送直接回成功，不重複扣款')]
    public function test_place_bet_failed_duplicate()
    {
        // Arrange
        $postData = $this->defaultPostData;

        $walletBalance = 1000.05;
        $this->wallet->update([
            'balance' => $walletBalance,
        ]);

        $rollingChips = 100.01;
        $this->rollingChipWallet->update([
            'balance' => $rollingChips,
        ]);

        $totalBalance = Common::numAdd($walletBalance, $rollingChips);
        $platformAmount = Common::numDiv($postData['amount'], $this->vendorInfo->rate, 4);

        $expectedBalance = Common::numSub($totalBalance, $platformAmount);

        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);
        $response2 = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);

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

        $response2->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::SUCCESS->value,
                'msg' => 'success',
                'merchantCode' => $this->merchantCode,
                'serialNo' => $this->defaultSerialNo,
                'transferId' => $postData['transferId'],
                'acctId' => $this->acctId,
                'balance' => Common::numMul($expectedBalance, $this->vendorInfo->rate, 4),
            ]);
    }
}
