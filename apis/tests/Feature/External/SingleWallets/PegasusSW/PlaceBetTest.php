<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PegasusSW;

use App\Helpers\Common;
use App\Models\TransactionLog;
use App\Vendors\Models\PgsswBetRecord;
use App\Vendors\PegasusSW\BetRecordStatusEnum;
use App\Vendors\PegasusSW\OperatorStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * php artisan test tests/Feature/External/SingleWallets/PegasusSW/PlaceBetTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/PegasusSW --compact
 */
class PlaceBetTest extends PgsswTestCase
{
    private function postData(array $overrides = []): array
    {
        return array_merge([
            'playerUniqueID' => $this->playerUniqueId,
            'sessionToken' => $this->sessionToken,
            'transactionNo' => 'TXN-BET-001',
            'gameID' => 1234,
            'betAmount' => 10.0,
        ], $overrides);
    }

    #[TestDox('餘額足夠首次下注：扣除注金、建立 BET 紀錄、建立 TransactionLog、回傳 code=0 及餘額')]
    #[Group('rules/vendor-target#R-8')]
    public function test_place_bet_deducts_balance_and_creates_record()
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);
        $data = $this->postData();

        $response = $this->postWithIdentity('/bet/placeBet', $data);
        $betRecord = PgsswBetRecord::first();
        $this->member->refresh();

        $platformBet = Common::numDiv($data['betAmount'], $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numSub($walletBalance, $platformBet, 4);

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::OK->value,
            'data' => [
                'balance' => $this->expectedResponseAmount($expectedBalance),
                'transactionNo' => $data['transactionNo'],
            ],
        ]);

        $this->assertSame($expectedBalance, $this->member->balance);
        $this->assertSame(1, PgsswBetRecord::count());
        $this->assertSame(1, TransactionLog::count());

        $this->assertSame($data['transactionNo'], $betRecord->bet_id);
        $this->assertSame($platformBet, $betRecord->bet);
        $this->assertSame('0.0000', $betRecord->win);
        $this->assertSame(Common::numMul($platformBet, -1, 4), $betRecord->win_lose);
        $this->assertSame(BetRecordStatusEnum::BET->value, $betRecord->status);
    }

    #[TestDox('重複 transactionNo：回傳 DUPLICATE_TRANSACTION，DB 僅一筆，餘額不重複扣')]
    #[Group('rules/vendor-target#R-10')]
    public function test_place_bet_returns_duplicate_transaction_for_same_id()
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);
        $data = $this->postData();

        $this->postWithIdentity('/bet/placeBet', $data);
        $response = $this->postWithIdentity('/bet/placeBet', $data);
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::DUPLICATE_TRANSACTION->value,
        ]);
        $this->assertSame(1, PgsswBetRecord::count());

        $platformBet = Common::numDiv($data['betAmount'], $this->vendorInfo->rate, 4);
        $this->assertSame(Common::numSub($walletBalance, $platformBet, 4), $this->member->balance);
    }

    #[TestDox('餘額不足：回傳 INSUFFICIENT_BALANCE，不建立任何紀錄')]
    #[Group('rules/vendor-target#R-9')]
    public function test_place_bet_returns_insufficient_balance()
    {
        $this->member->update(['balance' => '0.0000']);

        $response = $this->postWithIdentity('/bet/placeBet', $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::INSUFFICIENT_BALANCE->value,
        ]);
        $this->assertSame(0, PgsswBetRecord::count());
        $this->assertSame(0, TransactionLog::count());
    }

    #[TestDox('遊戲帳號停用：回傳 INVALID_SIGNATURE，不建立紀錄')]
    #[Group('rules/vendor-target#R-9')]
    public function test_place_bet_returns_error_when_account_disabled()
    {
        $this->member->update(['balance' => '1000.0000']);
        $this->account->update(['status' => 0]);

        $response = $this->postWithIdentity('/bet/placeBet', $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
        $this->assertSame(0, PgsswBetRecord::count());
    }

    #[TestDox('X-PROVIDER-IDENTITY 標頭錯誤：回傳 INVALID_SIGNATURE，不建立紀錄')]
    #[Group('rules/vendor-target#R-4')]
    public function test_place_bet_returns_error_when_identity_header_is_wrong()
    {
        $this->member->update(['balance' => '1000.0000']);

        $response = $this->withHeaders(['X-PROVIDER-IDENTITY' => 'wrong-identity'])
            ->postJson("{$this->baseUrl}/bet/placeBet", $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
        $this->assertSame(0, PgsswBetRecord::count());
    }

    #[TestDox('playerUniqueID 查無帳號：回傳 INVALID_SIGNATURE，不建立紀錄')]
    #[Group('rules/vendor-target#R-3')]
    public function test_place_bet_returns_error_when_player_not_found()
    {
        $response = $this->postWithIdentity('/bet/placeBet', $this->postData([
            'playerUniqueID' => 'unknown-player',
        ]));

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
        $this->assertSame(0, PgsswBetRecord::count());
    }
}
