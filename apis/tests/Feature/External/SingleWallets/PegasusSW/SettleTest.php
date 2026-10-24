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
 * php artisan test tests/Feature/External/SingleWallets/PegasusSW/SettleTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/PegasusSW --compact
 */
class SettleTest extends PgsswTestCase
{
    private function postData(array $overrides = []): array
    {
        return array_merge([
            'playerUniqueID' => $this->playerUniqueId,
            'transactionNo' => 'TXN-BET-001',
            'gameID' => 1234,
            'betAmount' => 10.0,
            'payoutAmount' => 20.0,
            'validAmount' => 10.0,
            'taxAmount' => 0.0,
            'settlementTime' => '2024-09-30T14:00:00Z',
        ], $overrides);
    }

    #[TestDox('正常結算（玩家贏）：加回 payout、betRecord status=SETTLE、建立 TransactionLog')]
    #[Group('rules/vendor-target#R-11')]
    public function test_settle_credits_payout_and_updates_record()
    {
        $balanceAfterBet = '990.0000';
        $this->member->update(['balance' => $balanceAfterBet]);
        $this->makeBetRecord();

        $data = $this->postData();
        $response = $this->postWithIdentity('/bet/settle', $data);
        $betRecord = PgsswBetRecord::first();
        $this->member->refresh();

        $platformPayout = Common::numDiv($data['payoutAmount'], $this->vendorInfo->rate, 4);
        $platformValidBet = Common::numDiv($data['validAmount'], $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numAdd($balanceAfterBet, $platformPayout, 4);

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::OK->value,
            'data' => [
                'balance' => $this->expectedResponseAmount($expectedBalance),
                'transactionNo' => $data['transactionNo'],
            ],
        ]);

        $this->assertSame(BetRecordStatusEnum::SETTLE->value, $betRecord->status);
        $this->assertSame($platformPayout, $betRecord->win);
        $this->assertSame($platformValidBet, $betRecord->bet);
        $this->assertSame(1, TransactionLog::count());
    }

    #[TestDox('玩家輸局 payoutAmount=0：餘額不增加、betRecord status=SETTLE、win=0')]
    #[Group('rules/vendor-target#R-11')]
    public function test_settle_zero_payout_on_loss()
    {
        $balanceAfterBet = '990.0000';
        $this->member->update(['balance' => $balanceAfterBet]);
        $this->makeBetRecord();

        $response = $this->postWithIdentity('/bet/settle', $this->postData([
            'payoutAmount' => 0.0,
            'validAmount' => 10.0,
        ]));
        $betRecord = PgsswBetRecord::first();
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::OK->value,
        ]);

        $this->assertSame(BetRecordStatusEnum::SETTLE->value, $betRecord->status);
        $this->assertSame('0.0000', $betRecord->win);
        $this->assertSame($balanceAfterBet, $this->member->balance);
    }

    #[TestDox('找不到 placeBet 紀錄：回傳 INVALID_STATUS')]
    #[Group('rules/vendor-target#R-12')]
    public function test_settle_returns_not_found_when_bet_does_not_exist()
    {
        $response = $this->postWithIdentity('/bet/settle', $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::INVALID_STATUS->value,
        ]);
    }

    #[TestDox('注單已是 SETTLE 狀態（重複 settle）：回傳 INVALID_STATUS，不重複加款')]
    #[Group('rules/vendor-target#R-12')]
    public function test_settle_returns_invalid_status_when_already_settled()
    {
        $this->member->update(['balance' => '1000.0000']);
        $this->makeBetRecord(['status' => BetRecordStatusEnum::SETTLE->value]);

        $response = $this->postWithIdentity('/bet/settle', $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::INVALID_STATUS->value,
        ]);
        $this->assertSame(0, TransactionLog::count());
    }

    #[TestDox('注單已是 ROLLBACK 狀態（已取消再 settle）：回傳 INVALID_STATUS')]
    #[Group('rules/vendor-target#R-12')]
    public function test_settle_returns_invalid_status_when_bet_is_cancelled()
    {
        $this->member->update(['balance' => '1000.0000']);
        $this->makeBetRecord(['status' => BetRecordStatusEnum::ROLLBACK->value]);

        $response = $this->postWithIdentity('/bet/settle', $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::INVALID_STATUS->value,
        ]);
    }

    #[TestDox('X-PROVIDER-IDENTITY 標頭錯誤：回傳 INVALID_SIGNATURE')]
    #[Group('rules/vendor-target#R-4')]
    public function test_settle_returns_error_when_identity_header_is_wrong()
    {
        $this->makeBetRecord();

        $response = $this->withHeaders(['X-PROVIDER-IDENTITY' => 'wrong-identity'])
            ->postJson("{$this->baseUrl}/bet/settle", $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
        $this->assertSame(0, TransactionLog::count());
    }
}
