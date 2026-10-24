<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PegasusSW;

use App\Helpers\Common;
use App\Models\TransactionLog;
use App\Vendors\Models\PgsswBetRecord;
use App\Vendors\Models\PgsswCancelLog;
use App\Vendors\PegasusSW\BetRecordStatusEnum;
use App\Vendors\PegasusSW\OperatorStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * php artisan test tests/Feature/External/SingleWallets/PegasusSW/CancelTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/PegasusSW --compact
 */
class CancelTest extends PgsswTestCase
{
    private function postData(array $overrides = []): array
    {
        return array_merge([
            'playerUniqueID' => $this->playerUniqueId,
            'transactionNo' => 'TXN-BET-001',
            'gameID' => 1234,
        ], $overrides);
    }

    #[TestDox('placeBet 後發 cancel：退還注金、betRecord status=ROLLBACK、CancelLog status=1')]
    #[Group('rules/vendor-target#R-15')]
    public function test_cancel_refunds_bet_and_updates_record_to_rollback()
    {
        $balanceAfterBet = '990.0000';
        $this->member->update(['balance' => $balanceAfterBet]);
        $betRecord = $this->makeBetRecord();

        $originalBet = $betRecord->bet;

        $response = $this->postWithIdentity('/bet/cancel', $this->postData());
        $betRecord->refresh();
        $this->member->refresh();

        $expectedBalance = Common::numAdd($balanceAfterBet, $originalBet, 4);

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::OK->value,
            'data' => ['transactionNo' => 'TXN-BET-001'],
        ]);

        $this->assertSame(BetRecordStatusEnum::ROLLBACK->value, $betRecord->status);
        $this->assertSame('0.0000', $betRecord->bet);
        $this->assertSame('0.0000', $betRecord->win);
        $this->assertSame($expectedBalance, $this->member->balance);
        $this->assertSame(1, TransactionLog::count());

        $cancelLog = PgsswCancelLog::first();
        $this->assertSame(1, $cancelLog->status);
    }

    #[TestDox('找不到對應 placeBet：回傳 code=0，不改變餘額，仍寫入 CancelLog')]
    #[Group('rules/vendor-target#R-17')]
    public function test_cancel_returns_ok_when_bet_record_not_found()
    {
        $balance = '1000.0000';
        $this->member->update(['balance' => $balance]);

        $response = $this->postWithIdentity('/bet/cancel', $this->postData());
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::OK->value,
        ]);
        $this->assertSame($balance, $this->member->balance);
        $this->assertSame(0, TransactionLog::count());
        $this->assertSame(1, PgsswCancelLog::count());
    }

    #[TestDox('同一 transactionNo 送兩次 cancel：第二次回傳 code=0（cancelLog 冪等保護）')]
    #[Group('rules/vendor-target#R-16')]
    public function test_cancel_returns_ok_when_already_processed()
    {
        $balanceAfterBet = '990.0000';
        $this->member->update(['balance' => $balanceAfterBet]);
        $this->makeBetRecord();

        $this->postWithIdentity('/bet/cancel', $this->postData());
        $response = $this->postWithIdentity('/bet/cancel', $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::OK->value,
            'data' => ['transactionNo' => 'TXN-BET-001'],
        ]);
        $this->assertSame(1, PgsswCancelLog::count());
    }

    #[TestDox('transactionNo 屬於其他玩家：回傳 code=0，不退款、不改注單')]
    #[Group('rules/vendor-target#R-17')]
    public function test_cancel_returns_ok_without_refund_when_username_mismatch()
    {
        $balance = '1000.0000';
        $this->member->update(['balance' => $balance]);

        // 建立屬於其他玩家的注單
        $this->makeBetRecord(['username' => 'OTHER_PLAYER']);

        $response = $this->postWithIdentity('/bet/cancel', $this->postData());
        $this->member->refresh();

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::OK->value,
        ]);
        $this->assertSame($balance, $this->member->balance);
        $this->assertSame(0, TransactionLog::count());

        // 注單狀態不變（仍為 BET）
        $betRecord = PgsswBetRecord::first();
        $this->assertSame(BetRecordStatusEnum::BET->value, $betRecord->status);
    }

    #[TestDox('X-PROVIDER-IDENTITY 標頭錯誤：回傳 INVALID_SIGNATURE')]
    #[Group('rules/vendor-target#R-4')]
    public function test_cancel_returns_error_when_identity_header_is_wrong()
    {
        $this->makeBetRecord();

        $response = $this->withHeaders(['X-PROVIDER-IDENTITY' => 'wrong-identity'])
            ->postJson("{$this->baseUrl}/bet/cancel", $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
        $this->assertSame(0, TransactionLog::count());
    }
}
