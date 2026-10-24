<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WudiSW;

use App\Enums\Transaction\TransactionEnum;
use App\Helpers\Common;
use App\Vendors\Models\WudiswBetRecord;
use App\Vendors\WudiSW\Datas\TransferInfoData;
use App\Vendors\WudiSW\Enums\BetRecordStatusEnum;
use App\Vendors\WudiSW\WudiSWService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

class WudiSWEndBetTest extends WudiSWTestCase
{
    private $betId = 'bet_order123';

    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Group('rules/vendor-wudisw#R-13')]
    public function test_end_bet()
    {
        // Arrange
        $betAmount = 100;
        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate);
        $placebet_BetRecord = WudiswBetRecord::create([
            'bet_id' => $this->betId,
            'username' => $this->username,
            'game_code' => $this->gameCode,
            'status' => BetRecordStatusEnum::START,
            'bet' => $platformBetAmount,
            'win_lose' => Common::numMul($platformBetAmount, -1),

            'bet_time' => '2023-10-01 04:00:00',
            'payout_time' => '2023-10-01 04:00:00',
        ]);

        $winAmount = 1000;
        $platformWinAmount = Common::numDiv($winAmount, $this->vendorInfo->rate);

        $walletBalance = 2000;
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $oldBalance = $walletBalance;
        $oldWalletBalance = $walletBalance;
        $newBalance = Common::numAdd($oldBalance, $platformWinAmount);
        $newWalletBalance = Common::numAdd($oldWalletBalance, $platformWinAmount);

        $dto = TransferInfoData::from([
            'amount' => $winAmount,
            'auth_code' => '',
            'betform_details' => json_encode([
                'round_id' => 'round123',
                'order_id' => $this->betId,
                'transaction_type' => 1,
                'special' => [],
                'bet' => $betAmount,
                'win' => $winAmount,
                'valid_bet' => $betAmount,
                'rake_amount' => '0',
                'diff' => Common::numSub($winAmount, $betAmount),
                'username' => $this->username,
                'player_name' => $this->username,
                'game_type' => 'slot',
                'game_code' => $this->gameCode,
                'detail_url' => '',
                'bet_time' => '2025-09-04T03:44:49.000-04:00',
                'end_time' => '2025-09-04T03:44:49-04:00',
                'result_time' => '2025-09-04T03:44:49-04:00',
            ]),
            'currency' => 'MYR',
            'game_code' => $this->gameCode,
            'game_type' => 'slot',
            'tran_id' => Str::uuid()->toString(),
            'trans_details' => json_encode([
                'round_id' => 'round123',
                'order_id' => $this->betId,
                'trans_amount' => $winAmount,
                'request_time' => time(),
            ]),
            'transfer_type' => 'end',
            'username' => $this->username,
        ]);

        // Act
        $result = (new WudiSWService)->transferEnd($dto);
        $this->wallet->refresh();

        // Assert
        $this->assertNotEmpty($result);
        $this->assertEquals(Common::numMul($newWalletBalance, $this->vendorInfo->rate), $result);
        $this->assertEquals(Common::numFloor($newWalletBalance, 4), $this->wallet->balance);

        $this->assertDatabaseHas('wudisw_bet_records', [
            'bet_id' => $this->betId,
            'username' => $this->username,
            'game_code' => $this->gameCode,
            'bet' => Common::numFloor($platformBetAmount, 4),
            'valid_bet' => Common::numFloor($platformBetAmount, 4),
            'win_lose' => Common::numSub($platformWinAmount, $platformBetAmount, 4),
            'status' => BetRecordStatusEnum::END,
        ]);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'agent_id' => $this->member->parent,
            'vendor_id' => $this->vendor->id,
            'point' => Common::numSub($oldBalance, $newBalance),
            'before_balance' => $oldBalance,
            'balance' => $newBalance,
            'type' => TransactionEnum::SEAMLESS_WALLET_BET_SETTLE,
            'status' => 999,
        ]);
    }

    #[Group('rules/vendor-wudisw#R-16')]
    public function test_duplicate_end_bet_response_contains_current_balance_data()
    {
        // Arrange：注單已結算，模擬三方重複請求派彩
        WudiswBetRecord::create([
            'bet_id' => $this->betId,
            'username' => $this->username,
            'game_code' => $this->gameCode,
            'status' => BetRecordStatusEnum::END,
            'bet' => 50,
            'win_lose' => 450,
            'bet_time' => '2023-10-01 04:00:00',
            'payout_time' => '2023-10-01 04:00:00',
        ]);

        $walletBalance = 2000;
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $params = [
            'amount' => 1000,
            'currency' => 'MYR',
            'game_code' => $this->gameCode,
            'game_type' => 'slot',
            'tran_id' => Str::uuid()->toString(),
            'trans_details' => json_encode([
                'round_id' => 'round123',
                'order_id' => $this->betId,
                'trans_amount' => 1000,
                'request_time' => time(),
            ]),
            'transfer_type' => 'end',
            'username' => $this->username,
        ];
        $params['auth_code'] = $this->makeAuthCode($params);

        // Act
        $response = $this->postJson($this->baseurl . '/transfer', $params);

        // Assert：code 1000 且 data 必須帶目前餘額，否則三方無法結單
        $response->assertOk()
            ->assertJsonPath('status.code', 1000)
            ->assertJsonPath('data.tran_id', $params['tran_id'])
            ->assertJsonStructure(['data' => ['balance', 'currency', 'tran_id']]);
        $this->assertEquals(
            Common::numMul($walletBalance, $this->vendorInfo->rate),
            $response->json('data.balance')
        );

        // 重複請求不得再加錢
        $this->wallet->refresh();
        $this->assertEquals(Common::numFloor($walletBalance, 4), $this->wallet->balance);
    }

    private function makeAuthCode(array $params): string
    {
        unset($params['trans_details'], $params['betform_details'], $params['auth_code']);
        ksort($params);

        $paramsString = '';
        foreach ($params as $key => $value) {
            $paramsString .= "{$key}={$value}&";
        }

        return md5($paramsString); // 測試 vendorInfo 的 MD5_KEY 為空字串
    }
}
