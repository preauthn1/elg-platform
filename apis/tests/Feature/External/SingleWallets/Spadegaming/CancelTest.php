<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Spadegaming;

use App\Helpers\Common;
use App\Models\RollingChipLog;
use App\Models\TotalBetRecord;
use App\Models\TransactionLog;
use App\Vendors\Models\SpadegamingswBetRecord;
use App\Vendors\SpadegamingSW\Enums\TransferTypeEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 取消下注 API 測試（api header = transfer，type = CANCEL）
 *
 * 驗證取消注單時的餘額退款邏輯：退款時先補回禮碼，剩餘部分才還入真金錢包，
 * 確保退款後總餘額回到下注前的狀態。
 *
 * - 成功（有禮碼錢包）：禮碼退回禮碼錢包，剩餘返還真金錢包
 * - 成功（無禮碼錢包）：全額退回真金錢包
 * - 冪等性：相同 transferId 重送時直接回傳成功，不重複退款
 *
 * 成功後預期狀態：
 * - SpadegamingswBetRecord +1（type = CANCEL，reference_bet_id 指向 PlaceBet）
 * - TransactionLog +2（PlaceBet 扣款 + Cancel 退款各一筆）
 * - TotalBetRecord 不建立（Cancel 不產生 TotalBetRecord）
 * - RollingChipLog +2（PlaceBet 扣禮碼 + Cancel 退禮碼各一筆）
 */
class CancelTest extends SpadegamingSWTestCase
{
    private $placeBetTransferId = '20240801142953555117852';

    private $cancelTransferId = '20240801142953555117852_type_2';

    private $placeBetPostData;

    private $cancelPostData;

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

        $this->cancelPostData = [
            'acctId' => $this->acctId,
            'merchantCode' => $this->merchantCode,
            'serialNo' => $this->defaultSerialNo,
            'transferId' => $this->cancelTransferId,
            'referenceId' => $this->placeBetTransferId,
            'type' => TransferTypeEnum::CANCEL,
            'currency' => 'MYR',
            'gameCode' => $this->game->game_type,
            'channel' => 'Mobile',

            'amount' => 300,
            'transferTime' => date('Y-m-d H:i:s'),
        ];
    }

    /** R-20：收到取消下注回調時，依參考編號找到原下注注單，把投注額退回會員錢包（原本扣的禮碼退回禮碼錢包），產生一筆取消注單，其輸贏為原下注的反向。 */
    #[Group('rules/vendor-spadegamingsw#R-20')]
    #[TestDox('取消下注後禮碼退回禮碼錢包、其餘退回真金，產生 CANCEL 注單')]
    public function test_cancel_success()
    {
        // Arrange
        $postData = $this->cancelPostData;

        // # Add balance
        $walletBalance = 1000.05;
        $this->wallet->update([
            'balance' => $walletBalance,
        ]);

        $originRollingChips = 100.01;
        $this->rollingChipWallet->update([
            'balance' => $originRollingChips,
        ]);

        $totalBalance = Common::numAdd($walletBalance, $originRollingChips, 4);

        // # create place bet record
        $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $this->placeBetPostData, $this->headers);

        // # Calc balance
        $originBetRecord = SpadegamingswBetRecord::where('bet_id', $this->placeBetTransferId)->first();

        // # calc costs when place bet.
        $rollingChipsCost = Common::numSub($originRollingChips, $this->placeBetPostData['amount'], 4);
        $walletCost = 0;
        if ($rollingChipsCost < 0) { // not enough
            $rollingChipsCost = $originRollingChips;
            $walletCost = Common::numSub($this->placeBetPostData['amount'], $rollingChipsCost, 4);
        }

        $expectedBalance = $totalBalance;

        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);
        $betRecord = SpadegamingswBetRecord::where('bet_id', $this->cancelTransferId)
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

        // Bet canceled, no win_lost.
        $this->assertEquals(0, $betRecord->bet);
        $this->assertEquals(Common::numMul($originBetRecord->win_lose, -1, 4), $betRecord->win_lose); // give back
        $this->assertEquals(Common::numMul($originBetRecord->rolling_chips, -1, 4), $betRecord->rolling_chips); // give back
        $this->assertEquals(TransferTypeEnum::CANCEL, $betRecord->type);

        // rolling chips add to rolling chip wallet, others to wallet.
        $this->rollingChipWallet->refresh();
        $this->wallet->refresh();
        $this->assertEquals($originRollingChips, $this->rollingChipWallet->balance);
        $this->assertEquals($walletBalance, $this->wallet->balance);

        // Records
        $this->assertEquals(2, SpadegamingswBetRecord::count());
        // 2026-05-19 起：cancel 經 BaseController::changeBalance 走 27 SETTLE 延用，整段 session = 一筆 log
        $this->assertEquals(1, TransactionLog::count());
        $this->assertEquals(0, TotalBetRecord::count()); // Remove
        $this->assertEquals(2, RollingChipLog::count());
    }

    /** R-20：收到取消下注回調時，依參考編號找到原下注注單，把投注額退回會員錢包（原本扣的禮碼退回禮碼錢包），產生一筆取消注單，其輸贏為原下注的反向。 */
    #[Group('rules/vendor-spadegamingsw#R-20')]
    #[TestDox('沒有禮碼錢包時取消下注全額退回真金錢包')]
    public function test_cancel_success_without_rolling_chip_wallet()
    {
        // Arrange
        $postData = $this->cancelPostData;

        // # Add balance
        $walletBalance = 1000.05;
        $this->wallet->update([
            'balance' => $walletBalance,
        ]);

        $originRollingChips = 0;
        $this->rollingChipWallet->delete();

        $totalBalance = Common::numAdd($walletBalance, $originRollingChips, 4);

        // # create place bet record
        $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $this->placeBetPostData, $this->headers);

        // # Calc balance
        $originBetRecord = SpadegamingswBetRecord::where('bet_id', $this->placeBetTransferId)->first();

        // # calc costs when place bet.
        $rollingChipsCost = Common::numSub($originRollingChips, $this->placeBetPostData['amount'], 4);
        $walletCost = 0;
        if ($rollingChipsCost < 0) { // not enough
            $rollingChipsCost = $originRollingChips;
            $walletCost = Common::numSub($this->placeBetPostData['amount'], $rollingChipsCost, 4);
        }

        $expectedBalance = $totalBalance;

        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);
        $betRecord = SpadegamingswBetRecord::where('bet_id', $this->cancelTransferId)
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

        // Bet canceled, no win_lost.
        $this->assertEquals(0, $betRecord->bet);
        $this->assertEquals(Common::numMul($originBetRecord->win_lose, -1, 4), $betRecord->win_lose); // give back
        $this->assertEquals(Common::numMul($originBetRecord->rolling_chips, -1, 4), $betRecord->rolling_chips); // give back
        $this->assertEquals(TransferTypeEnum::CANCEL, $betRecord->type);

        // rolling chips add to rolling chip wallet, others to wallet.
        $this->rollingChipWallet->refresh();
        $this->wallet->refresh();
        $this->assertEquals($originRollingChips, $this->rollingChipWallet->balance);
        $this->assertEquals($walletBalance, $this->wallet->balance);

        // Records
        $this->assertEquals(2, SpadegamingswBetRecord::count());
        // 2026-05-19 起：cancel 經 BaseController::changeBalance 走 27 SETTLE 延用，整段 session = 一筆 log
        $this->assertEquals(1, TransactionLog::count());
        $this->assertEquals(0, TotalBetRecord::count()); // Remove
    }

    /** R-22：同一筆下注已被取消過，再收到針對它的取消回調時（即使轉帳編號不同），回當前餘額與成功碼 0，不再退款；兩筆取消幾乎同時到達時也只退一次。 */
    #[Group('rules/vendor-spadegamingsw#R-22')]
    #[TestDox('同一 transferId 的取消重送直接回成功，不重複退款')]
    public function test_cancel_failed_duplicate()
    {
        // Arrange
        $postData = $this->cancelPostData;

        // # Add balance
        $walletBalance = 1000.05;
        $this->wallet->update([
            'balance' => $walletBalance,
        ]);

        $originRollingChips = 100.01;
        $this->rollingChipWallet->update([
            'balance' => $originRollingChips,
        ]);

        $totalBalance = Common::numAdd($walletBalance, $originRollingChips, 4);

        // # create place bet record
        $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $this->placeBetPostData, $this->headers);

        // # Calc balance
        $originBetRecord = SpadegamingswBetRecord::where('bet_id', $this->placeBetTransferId)->first();

        // # calc costs when place bet.
        $rollingChipsCost = Common::numSub($originRollingChips, $this->placeBetPostData['amount'], 4);
        $walletCost = 0;
        if ($rollingChipsCost < 0) { // not enough
            $rollingChipsCost = $originRollingChips;
            $walletCost = Common::numSub($this->placeBetPostData['amount'], $rollingChipsCost, 4);
        }

        $expectedBalance = $totalBalance;

        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);

        $betRecord = SpadegamingswBetRecord::where('bet_id', $this->cancelTransferId)
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

        // Bet canceled, no win_lost.
        $this->assertEquals(0, $betRecord->bet);
        $this->assertEquals(Common::numMul($originBetRecord->win_lose, -1, 4), $betRecord->win_lose); // give back
        $this->assertEquals(Common::numMul($originBetRecord->rolling_chips, -1, 4), $betRecord->rolling_chips); // give back
        $this->assertEquals(TransferTypeEnum::CANCEL, $betRecord->type);

        // rolling chips add to rolling chip wallet, others to wallet.
        $this->rollingChipWallet->refresh();
        $this->wallet->refresh();
        $this->assertEquals($originRollingChips, $this->rollingChipWallet->balance);
        $this->assertEquals($walletBalance, $this->wallet->balance);

        // Records
        $this->assertEquals(2, SpadegamingswBetRecord::count());
        // 2026-05-19 起：cancel 經 BaseController::changeBalance 走 27 SETTLE 延用，整段 session = 一筆 log
        $this->assertEquals(1, TransactionLog::count());
        $this->assertEquals(0, TotalBetRecord::count()); // Remove
        $this->assertEquals(2, RollingChipLog::count());
    }

    /**
     * R-22：同一筆下注已被取消過，再收到針對它的取消回調時（即使轉帳編號不同），回當前餘額與成功碼 0，不再退款；兩筆取消幾乎同時到達時也只退一次。
     *
     * SG 未收到 placeBet 回應時會重發 cancel，每次帶新的 transferId；冪等鍵若只看撤單自己的
     * transferId，每一次重送都會再退一次原下注金額（target/op_tickets#3098）。
     */
    #[Group('rules/vendor-spadegamingsw#R-22')]
    #[TestDox('同一筆下注換新 transferId 重送取消，回成功但不再退款')]
    public function test_cancel_rejects_retry_with_new_transfer_id_on_same_reference()
    {
        // Arrange
        $walletBalance = 1000.05;
        $this->wallet->update([
            'balance' => $walletBalance,
        ]);

        $originRollingChips = 100.01;
        $this->rollingChipWallet->update([
            'balance' => $originRollingChips,
        ]);

        $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $this->placeBetPostData, $this->headers);
        $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $this->cancelPostData, $this->headers);

        $this->wallet->refresh();
        $this->rollingChipWallet->refresh();
        $balanceAfterCancel = $this->wallet->balance;
        $rollingChipsAfterCancel = $this->rollingChipWallet->balance;
        $transactionLogsAfterCancel = TransactionLog::count();

        // Act：同一筆下注被重送六次撤單，每次都換一個新的 transferId
        for ($retry = 1; $retry <= 6; $retry++) {
            $retryPostData = $this->cancelPostData;
            $retryPostData['transferId'] = $this->cancelTransferId . '_retry_' . $retry;

            $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $retryPostData, $this->headers);

            // Assert：回 code 0 而非 109——api.md 4.3.2 明訂回 109 時 SG 會持續重送
            $response->assertStatus(200)
                ->assertJson([
                    'code' => 0,
                    'msg' => 'success',
                    'transferId' => $retryPostData['transferId'],
                    'acctId' => $this->acctId,
                    'balance' => Common::numMul(
                        Common::numAdd($balanceAfterCancel, $rollingChipsAfterCancel, 4),
                        $this->vendorInfo->rate,
                        4
                    ),
                ]);

            $this->assertNull(SpadegamingswBetRecord::where('bet_id', $retryPostData['transferId'])->first());
        }

        // Assert：錢包與帳變流水都停在第一次撤單後的狀態
        $this->wallet->refresh();
        $this->rollingChipWallet->refresh();
        $this->assertEquals($balanceAfterCancel, $this->wallet->balance);
        $this->assertEquals($rollingChipsAfterCancel, $this->rollingChipWallet->balance);
        $this->assertEquals($transactionLogsAfterCancel, TransactionLog::count());

        // Assert：撤單列仍只有第一次那筆
        $this->assertEquals(1, SpadegamingswBetRecord::where('type', TransferTypeEnum::CANCEL)->count());
        $this->assertEquals(2, SpadegamingswBetRecord::count());
        $this->assertEquals(0, TotalBetRecord::count());
        // 本缺陷會把真金與禮碼各退一次，禮碼流水也要停在「下注扣一筆、撤單退一筆」
        $this->assertEquals(2, RollingChipLog::count());
    }

    /**
     * R-23：不同筆下注各自可以被取消一次，互不影響。
     *
     * 閘門的粒度是「同一筆下注」，不是「同一個玩家」：不同下注各自撤一次都必須放行。
     */
    #[Group('rules/vendor-spadegamingsw#R-23')]
    #[TestDox('不同筆下注各自可以取消一次')]
    public function test_cancel_allows_each_bet_to_be_canceled_once()
    {
        // Arrange
        $walletBalance = 1000.05;
        $this->wallet->update([
            'balance' => $walletBalance,
        ]);

        $this->rollingChipWallet->delete();

        $secondPlaceBetTransferId = $this->placeBetTransferId . '_second';
        $secondPlaceBetPostData = $this->placeBetPostData;
        $secondPlaceBetPostData['transferId'] = $secondPlaceBetTransferId;

        $secondCancelPostData = $this->cancelPostData;
        $secondCancelPostData['transferId'] = $secondPlaceBetTransferId . '_type_2';
        $secondCancelPostData['referenceId'] = $secondPlaceBetTransferId;

        $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $this->placeBetPostData, $this->headers);
        $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $secondPlaceBetPostData, $this->headers);

        // Act
        $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $this->cancelPostData, $this->headers)
            ->assertStatus(200)
            ->assertJson(['code' => 0]);

        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $secondCancelPostData, $this->headers);

        // Assert：第二筆下注的撤單沒有被第一筆的撤單擋掉，兩筆下注都退回
        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'msg' => 'success',
                'transferId' => $secondCancelPostData['transferId'],
            ]);

        $this->wallet->refresh();
        $this->assertEquals($walletBalance, $this->wallet->balance);

        $this->assertEquals(2, SpadegamingswBetRecord::where('type', TransferTypeEnum::CANCEL)->count());
        $this->assertEquals(4, SpadegamingswBetRecord::count());
    }
}
