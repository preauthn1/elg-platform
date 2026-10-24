<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Spadegaming;

use App\Helpers\Common;
use App\Models\Game;
use App\Models\TotalBetRecord;
use App\Models\TransactionLog;
use App\Vendors\Models\SpadegamingswBetRecord;
use App\Vendors\SpadegamingSW\Enums\TransferTypeEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 派彩 API 測試（api header = transfer，type = PAYOUT）
 *
 * 驗證遊戲結束後派彩的入帳邏輯與 TotalBetRecord 更新：
 * - 成功：派彩金額入真金錢包（不入禮碼），TotalBetRecord 以 referenceId 對應 PlaceBet 更新
 * - 棋牌遊戲贏（gameCode 以 C- 開頭，派彩 > 投注）：valid_bet 應為 0
 * - 棋牌遊戲輸（派彩 < 投注）：valid_bet 應為 win_lose 的絕對值
 * - 冪等性：相同 transferId 重送時直接回傳成功，不重複入帳
 *
 * 成功後預期狀態：
 * - SpadegamingswBetRecord +1（type = PAYOUT，reference_bet_id 指向 PlaceBet）
 * - TransactionLog +1（僅 Payout 的入款，PlaceBet 的扣款已在 PlaceBet 時建立）
 * - TotalBetRecord +1（createOrUpdate 以 PlaceBet 的 bet_id 對應）
 */
class PayoutTest extends SpadegamingSWTestCase
{
    private $placeBetTransferId = '20240801142953555117852';

    private $payoutTransferId = '20240801142953555117852_type_4';

    private $placeBetPostData;

    private $payoutPostData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->placeBetPostData = [
            'acctId' => $this->acctId,
            'merchantCode' => $this->merchantCode,
            'serialNo' => $this->defaultSerialNo,
            'transferId' => $this->placeBetTransferId,
            'type' => TransferTypeEnum::PLACE_BET,
            'currency' => 'MYR',
            'gameCode' => $this->game->game_type,
            'channel' => 'Mobile',
            'amount' => 300,
            'transferTime' => date('Y-m-d H:i:s'),
        ];
        $this->payoutPostData = [
            'acctId' => $this->acctId,
            'merchantCode' => $this->merchantCode,
            'serialNo' => $this->defaultSerialNo,
            'transferId' => $this->payoutTransferId,
            'referenceId' => $this->placeBetTransferId,
            'type' => TransferTypeEnum::PAYOUT,
            'currency' => 'MYR',
            'gameCode' => $this->game->game_type,
            'channel' => 'Mobile',
            'amount' => 3000,
            'transferTime' => date('Y-m-d H:i:s'),
        ];
    }

    /** R-13：收到派彩回調時，依參考編號（referenceId）找到原下注注單，派彩額加入會員錢包，產生一筆派彩注單，並建立或更新該局總注單；輸局派彩 0 也會送達並建總注單。 */
    #[Group('rules/vendor-spadegamingsw#R-13')]
    #[TestDox('派彩入真金錢包，產生 PAYOUT 注單並更新該局總注單')]
    public function test_payout_success()
    {
        // Arrange
        $postData = $this->payoutPostData;

        $walletBalance = 1000.05;
        $rollingChipBalance = 100.01;
        $this->wallet->update(['balance' => $walletBalance]);
        $this->rollingChipWallet->update(['balance' => $rollingChipBalance]);

        // # create place bet record
        $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $this->placeBetPostData, $this->headers);

        // # calc costs when place bet.
        $placeBetAmount = Common::numDiv($this->placeBetPostData['amount'], $this->vendorInfo->rate);
        $rollingChipsCost = Common::numSub($rollingChipBalance, $placeBetAmount);
        $walletCost = 0;
        if ($rollingChipsCost < 0) { // not enough
            $rollingChipsCost = $rollingChipBalance;
            $walletCost = Common::numSub($placeBetAmount, $rollingChipsCost);
        }

        // # update balances after place bet.
        $walletBalance = Common::numSub($walletBalance, $walletCost);
        $rollingChipBalance = Common::numSub($rollingChipBalance, $rollingChipsCost);

        $totalBalance = Common::numAdd($walletBalance, $rollingChipBalance);
        $platformAmount = Common::numDiv($postData['amount'], $this->vendorInfo->rate, 4);

        $expectedBalance = Common::numAdd($totalBalance, $platformAmount);

        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);
        $betRecord = SpadegamingswBetRecord::where('bet_id', $this->payoutTransferId)
            ->where('reference_bet_id', $this->placeBetTransferId)
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
        $this->assertEquals(TransferTypeEnum::PAYOUT, $betRecord->type);

        // Amount should add to wallet not rolling chip.
        $this->rollingChipWallet->refresh();
        $this->wallet->refresh();
        $this->assertEquals($rollingChipBalance, $this->rollingChipWallet->balance);
        $this->assertEquals(Common::numAdd($walletBalance, $platformAmount, 4), $this->wallet->balance);

        // Records
        $this->assertEquals(2, SpadegamingswBetRecord::count());
        $this->assertEquals(1, TransactionLog::count());
        $this->assertEquals(1, TotalBetRecord::count()); // Update
    }

    /** R-16：棋牌類遊戲（遊戲代碼以 C- 開頭）派彩時，該局玩家淨贏則有效投注歸零，淨輸則有效投注等於淨輸額。 */
    #[Group('rules/vendor-spadegamingsw#R-16')]
    #[TestDox('棋牌遊戲淨贏時總注單的有效投注為 0')]
    public function test_payout_success_table_game_wingame_validbet_should_be_zero()
    {
        // Arrange
        $postData = $this->payoutPostData;

        $postData['gameCode'] = 'C-WT01';
        Game::factory()->create([
            'vendor_id' => $this->vendorInfo->vendor_id,
            'game_type' => $postData['gameCode'],
            'name' => 'test',
            'image' => '',
            'valid_bet_rate' => $this->vendor->valid_bet_rate,
        ]);

        $walletBalance = 1000.05;
        $rollingChipBalance = 100.01;
        $this->wallet->update(['balance' => $walletBalance]);
        $this->rollingChipWallet->update(['balance' => $rollingChipBalance]);

        // # create place bet record
        $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $this->placeBetPostData, $this->headers);

        // # calc costs when place bet.
        $placeBetAmount = Common::numDiv($this->placeBetPostData['amount'], $this->vendorInfo->rate);
        $rollingChipsCost = Common::numSub($rollingChipBalance, $placeBetAmount);
        $walletCost = 0;
        if ($rollingChipsCost < 0) { // not enough
            $rollingChipsCost = $rollingChipBalance;
            $walletCost = Common::numSub($placeBetAmount, $rollingChipsCost);
        }

        // # update balances after place bet.
        $walletBalance = Common::numSub($walletBalance, $walletCost);
        $rollingChipBalance = Common::numSub($rollingChipBalance, $rollingChipsCost);

        $totalBalance = Common::numAdd($walletBalance, $rollingChipBalance);
        $platformAmount = Common::numDiv($postData['amount'], $this->vendorInfo->rate, 4);

        $expectedBalance = Common::numAdd($totalBalance, $platformAmount);
        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);
        $betRecord = SpadegamingswBetRecord::where('bet_id', $this->payoutTransferId)
            ->where('reference_bet_id', $this->placeBetTransferId)
            ->first();
        $totalBetRecord = TotalBetRecord::first();

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
        $this->assertEquals(TransferTypeEnum::PAYOUT, $betRecord->type);

        // Amount should add to wallet not rolling chip.
        $this->rollingChipWallet->refresh();
        $this->wallet->refresh();
        $this->assertEquals($rollingChipBalance, $this->rollingChipWallet->balance);
        $this->assertEquals(Common::numAdd($walletBalance, $platformAmount, 4), $this->wallet->balance);

        // Total bet record, since it's prepaid game, it's valid_bet should be 0.
        $this->assertNotEmpty($totalBetRecord);
        $this->assertEquals($betRecord->reference_bet_id, $totalBetRecord->reference_no);
        $this->assertEquals(0, $totalBetRecord->valid_bet);

        // Records
        $this->assertEquals(2, SpadegamingswBetRecord::count());
        $this->assertEquals(1, TransactionLog::count());
        $this->assertEquals(1, TotalBetRecord::count()); // Update
    }

    /** R-16：棋牌類遊戲（遊戲代碼以 C- 開頭）派彩時，該局玩家淨贏則有效投注歸零，淨輸則有效投注等於淨輸額。 */
    #[Group('rules/vendor-spadegamingsw#R-16')]
    #[TestDox('棋牌遊戲淨輸時總注單的有效投注等於淨輸額')]
    public function test_payout_success_table_game_losegame_validbet_should_be_negative_win_lose()
    {
        // Arrange
        $postData = $this->payoutPostData;

        $postData['gameCode'] = 'C-WT01';
        $postData['amount'] = 100;
        Game::factory()->create([
            'vendor_id' => $this->vendorInfo->vendor_id,
            'game_type' => $postData['gameCode'],
            'name' => 'test',
            'image' => '',
            'valid_bet_rate' => $this->vendor->valid_bet_rate,
        ]);

        $walletBalance = 1000.05;
        $rollingChipBalance = 100.01;
        $this->wallet->update(['balance' => $walletBalance]);
        $this->rollingChipWallet->update(['balance' => $rollingChipBalance]);

        // # create place bet record
        $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $this->placeBetPostData, $this->headers);

        // # calc costs when place bet.
        $placeBetAmount = Common::numDiv($this->placeBetPostData['amount'], $this->vendorInfo->rate);
        $rollingChipsCost = Common::numSub($rollingChipBalance, $placeBetAmount);
        $walletCost = 0;
        if ($rollingChipsCost < 0) { // not enough
            $rollingChipsCost = $rollingChipBalance;
            $walletCost = Common::numSub($placeBetAmount, $rollingChipsCost);
        }

        // # update balances after place bet.
        $walletBalance = Common::numSub($walletBalance, $walletCost);
        $rollingChipBalance = Common::numSub($rollingChipBalance, $rollingChipsCost);

        $totalBalance = Common::numAdd($walletBalance, $rollingChipBalance);
        $platformAmount = Common::numDiv($postData['amount'], $this->vendorInfo->rate, 4);

        $expectedBalance = Common::numAdd($totalBalance, $platformAmount);
        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);
        $betRecord = SpadegamingswBetRecord::where('bet_id', $this->payoutTransferId)
            ->where('reference_bet_id', $this->placeBetTransferId)
            ->first();
        $totalBetRecord = TotalBetRecord::first();

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
        $this->assertEquals(TransferTypeEnum::PAYOUT, $betRecord->type);

        // Amount should add to wallet not rolling chip.
        $this->rollingChipWallet->refresh();
        $this->wallet->refresh();
        $this->assertEquals($rollingChipBalance, $this->rollingChipWallet->balance);
        $this->assertEquals(Common::numAdd($walletBalance, $platformAmount, 4), $this->wallet->balance);

        // Total bet record, since it's prepaid game, it's valid_bet should be 0.
        $this->assertNotEmpty($totalBetRecord);
        $this->assertEquals($betRecord->reference_bet_id, $totalBetRecord->reference_no);
        $this->assertEquals(Common::numMul($totalBetRecord->win_lose, -1, 4), $totalBetRecord->valid_bet);

        // Records
        $this->assertEquals(2, SpadegamingswBetRecord::count());
        $this->assertEquals(1, TransactionLog::count());
        $this->assertEquals(1, TotalBetRecord::count()); // Update
    }

    /** R-15：同一個轉帳編號的派彩重複送達時，回當前餘額與成功碼 0，不重複加款。 */
    #[Group('rules/vendor-spadegamingsw#R-15')]
    #[TestDox('同一 transferId 的派彩重送直接回成功，不重複入帳')]
    public function test_payout_failed_duplicate()
    {
        // Arrange
        $postData = $this->payoutPostData;

        $walletBalance = 1000.05;
        $rollingChipBalance = 100.01;
        $this->wallet->update(['balance' => $walletBalance]);
        $this->rollingChipWallet->update(['balance' => $rollingChipBalance]);

        // # create place bet record
        $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $this->placeBetPostData, $this->headers);

        // # calc costs when place bet.
        $placeBetAmount = Common::numDiv($this->placeBetPostData['amount'], $this->vendorInfo->rate);
        $rollingChipsCost = Common::numSub($rollingChipBalance, $placeBetAmount);
        $walletCost = 0;
        if ($rollingChipsCost < 0) { // not enough
            $rollingChipsCost = $rollingChipBalance;
            $walletCost = Common::numSub($placeBetAmount, $rollingChipsCost);
        }

        // # update balances after place bet.
        $walletBalance = Common::numSub($walletBalance, $walletCost);
        $rollingChipBalance = Common::numSub($rollingChipBalance, $rollingChipsCost);

        $totalBalance = Common::numAdd($walletBalance, $rollingChipBalance);
        $platformAmount = Common::numDiv($postData['amount'], $this->vendorInfo->rate, 4);

        $expectedBalance = Common::numAdd($totalBalance, $platformAmount);
        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers); // duplicate

        $betRecord = SpadegamingswBetRecord::where('bet_id', $this->payoutTransferId)
            ->where('reference_bet_id', $this->placeBetTransferId)
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
        $this->assertEquals(TransferTypeEnum::PAYOUT, $betRecord->type);

        // Amount should add to wallet not rolling chip.
        $this->rollingChipWallet->refresh();
        $this->wallet->refresh();
        $this->assertEquals($rollingChipBalance, $this->rollingChipWallet->balance);
        $this->assertEquals(Common::numAdd($walletBalance, $platformAmount, 4), $this->wallet->balance);

        // Records
        $this->assertEquals(2, SpadegamingswBetRecord::count());
        $this->assertEquals(1, TransactionLog::count());
        $this->assertEquals(1, TotalBetRecord::count()); // Update
    }
}
