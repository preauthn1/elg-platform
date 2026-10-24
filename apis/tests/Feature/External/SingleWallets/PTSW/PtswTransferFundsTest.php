<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\Models\PtswBetRecord;
use App\Vendors\PlayTechSW\BetRecordStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * PTSW transferFunds（bonus 資金回轉運營商錢包）端點基本行為測試。
 *
 * php artisan test tests/Feature/External/SingleWallets/PTSW/PtswTransferFundsTest.php --compact
 */
class PtswTransferFundsTest extends PtswTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 見 PtswSettlementTest::setUp() 註解。
        BotNotify::shouldReceive('send')->zeroOrMoreTimes();
    }

    #[TestDox('transferFunds：成功直接加錢，建立 status=SETTLE 且 bet_id=null 的 PtswBetRecord')]
    #[Group('rules/vendor-ptsw#R-27')]
    public function test_transfer_funds_success_credits_balance_and_creates_bet_record(): void
    {
        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $amount = '50.00';

        $response = $this->postJson("{$this->baseurl}/transferFunds", [
            'requestId' => 'req-transfer-1',
            'username' => $this->username,
            'transactionCode' => 'tc-transfer-1',
            'transactionDate' => '2026-06-02T09:00:00Z',
            'amount' => $amount,
            'bonusInstanceCode' => 'bonus-instance-001',
            'bonusTemplateId' => 'bonus-template-001',
            'type' => 'BONUS',
            'bonusInfo' => [
                'remoteBonusCode' => 'remote-bonus-001',
                'bonusInstanceCode' => 'bonus-instance-001',
                'bonusTemplateId' => 'bonus-template-001',
                'bonusType' => 'CASH',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonMissing(['error']);

        $this->assertEquals(1, PtswBetRecord::count());
        $betRecord = PtswBetRecord::first();
        $this->assertNull($betRecord->bet_id);
        $this->assertEquals('tc-transfer-1', $betRecord->transaction_code);
        $this->assertEquals(BetRecordStatusEnum::SETTLE->value, $betRecord->status);
        $this->assertEquals(Common::numAdd('0', $amount, 4), $betRecord->win_lose);

        $this->assertSame(1, TotalBetRecord::query()->where('vendor_id', $this->vendor->id)->count());

        $this->member->refresh();
        $this->assertEquals(
            Common::numAdd($initialBalance, $amount, 4),
            $this->member->balance,
        );
    }

    #[TestDox('transferFunds：相同 requestId 重送為冪等，不重複加錢也不重複建立紀錄')]
    #[Group('rules/vendor-ptsw#R-27')]
    public function test_transfer_funds_duplicate_request_id_is_idempotent(): void
    {
        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $amount = '50.00';
        $payload = [
            'requestId' => 'req-transfer-idem-1',
            'username' => $this->username,
            'transactionCode' => 'tc-transfer-idem-1',
            'transactionDate' => '2026-06-02T09:10:00Z',
            'amount' => $amount,
            'bonusInstanceCode' => 'bonus-instance-idem',
            'bonusTemplateId' => 'bonus-template-idem',
            'type' => 'BONUS',
            'bonusInfo' => [
                'remoteBonusCode' => 'remote-bonus-idem',
                'bonusInstanceCode' => 'bonus-instance-idem',
                'bonusTemplateId' => 'bonus-template-idem',
                'bonusType' => 'CASH',
            ],
        ];

        $this->postJson("{$this->baseurl}/transferFunds", $payload)
            ->assertStatus(200)
            ->assertJsonMissing(['error']);

        $this->postJson("{$this->baseurl}/transferFunds", $payload)
            ->assertStatus(200)
            ->assertJsonMissing(['error']);

        $this->assertEquals(1, PtswBetRecord::count(), '相同 requestId 重送不應建立新的 PtswBetRecord');
        $this->assertSame(
            1,
            TotalBetRecord::query()->where('vendor_id', $this->vendor->id)->count(),
            '相同 requestId 重送不應重複建立 TotalBetRecord',
        );

        $this->member->refresh();
        $this->assertEquals(
            Common::numAdd($initialBalance, $amount, 4),
            $this->member->balance,
            '相同 requestId 重送不應重複加錢',
        );
    }
}
