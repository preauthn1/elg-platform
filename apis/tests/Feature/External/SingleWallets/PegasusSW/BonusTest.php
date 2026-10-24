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
 * 5.2.3 bonus / 免費遊戲結算（betAmount 恆 0，無 placeBet 行為）
 *
 * php artisan test tests/Feature/External/SingleWallets/PegasusSW/BonusTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/PegasusSW --compact
 */
class BonusTest extends PgsswTestCase
{
    private function postData(array $overrides = []): array
    {
        return array_merge([
            'playerUniqueID' => $this->playerUniqueId,
            'transactionNo' => 'TXN-BONUS-001',
            'gameID' => 1234,
            'betAmount' => 0.0,
            'payoutAmount' => 50.0,
            'validAmount' => 0.0,
            'taxAmount' => 0.0,
            'settlementTime' => '2024-09-30T14:00:00Z',
        ], $overrides);
    }

    #[TestDox('首次 bonus settle：加回 payout、建立 bet=0 的 betRecord、回傳標準 response 格式')]
    #[Group('rules/vendor-target#R-18')]
    public function test_bonus_credits_payout_and_returns_standard_response()
    {
        $balance = '1000.0000';
        $this->member->update(['balance' => $balance]);
        $data = $this->postData();

        $response = $this->postWithIdentity('/bonus/settle', $data);
        $betRecord = PgsswBetRecord::first();
        $this->member->refresh();

        $platformPayout = Common::numDiv($data['payoutAmount'], $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numAdd($balance, $platformPayout, 4);

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::OK->value,
            'data' => [
                'balance' => $this->expectedResponseAmount($expectedBalance),
                'transactionNo' => $data['transactionNo'],
            ],
        ]);

        $this->assertSame('0.0000', $betRecord->bet);
        $this->assertSame($platformPayout, $betRecord->win);
        $this->assertSame($platformPayout, $betRecord->win_lose);
        $this->assertSame(1, TransactionLog::count());
    }

    #[TestDox('bonus settle 後注單狀態為已結算（SETTLE）— 對標 API_External')]
    #[Group('rules/vendor-target#R-18')]
    public function test_bonus_marks_bet_record_as_settled()
    {
        $this->member->update(['balance' => '1000.0000']);
        $data = $this->postData();

        $this->postWithIdentity('/bonus/settle', $data)
            ->assertJson(['code' => OperatorStatusEnum::OK->value]);

        $this->assertDatabaseHas('pgssw_bet_records', [
            'bet_id' => $data['transactionNo'],
            'status' => BetRecordStatusEnum::SETTLE->value,
        ]);
    }

    #[TestDox('重複 transactionNo：回傳 DUPLICATE_TRANSACTION，DB 僅一筆')]
    #[Group('rules/vendor-target#R-18')]
    public function test_bonus_returns_duplicate_transaction_for_same_id()
    {
        $this->member->update(['balance' => '1000.0000']);
        $data = $this->postData();

        $this->postWithIdentity('/bonus/settle', $data);
        $response = $this->postWithIdentity('/bonus/settle', $data);

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::DUPLICATE_TRANSACTION->value,
        ]);
        $this->assertSame(1, PgsswBetRecord::count());
    }

    #[TestDox('X-PROVIDER-IDENTITY 標頭錯誤：回傳 INVALID_SIGNATURE')]
    public function test_bonus_returns_error_when_identity_header_is_wrong()
    {
        $this->member->update(['balance' => '1000.0000']);

        $response = $this->withHeaders(['X-PROVIDER-IDENTITY' => 'wrong-identity'])
            ->postJson("{$this->baseUrl}/bonus/settle", $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
        $this->assertSame(0, PgsswBetRecord::count());
        $this->assertSame(0, TransactionLog::count());
    }
}
