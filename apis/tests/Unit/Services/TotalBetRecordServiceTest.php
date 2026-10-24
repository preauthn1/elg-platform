<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\TotalBetRecord;
use App\Services\TotalBetRecordService;
use App\Vendors\Models\AbstractBetRecord;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * TotalBetRecordService 總投注紀錄服務測試
 *
 * php artisan test tests/Unit/Services/TotalBetRecordServiceTest.php
 */
class TotalBetRecordServiceTest extends TestCase
{
    private $service;

    private $vendorId = 1;

    private $memberId = 1;

    private $betRecord;

    private int $transactionLogId = 1234;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TotalBetRecordService($this->vendorId, $this->memberId);

        // 基底不猜欄位名（01108c556），測試替身也要照規矩宣告
        $this->betRecord = new class extends AbstractBetRecord
        {
            protected ?string $betColumn = 'bet';

            protected ?string $validBetColumn = 'valid_bet';

            protected ?string $winLoseColumn = 'win_lose';

            protected ?string $rollingChipColumn = 'rolling_chips';

            protected ?string $betIdColumn = 'bet_id';

            protected ?string $payoutTimeColumn = 'payout_time';

            protected ?string $betTimeColumn = 'bet_time';
        };
        $this->betRecord->id = 1;
        $this->betRecord->bet_id = 'test_bet_id';
        $this->betRecord->game_code = 'test_game';
        $this->betRecord->bet = 100;
        $this->betRecord->valid_bet = 100;
        $this->betRecord->win_lose = 50;
        $this->betRecord->rolling_chips = 0;
        $this->betRecord->bet_time = '2023-01-01 00:00:00';
        $this->betRecord->payout_time = '2023-01-01 00:00:00';
        $this->betRecord->updated_at = '2023-01-01 00:00:00';
    }

    public function test_create_total_bet_record_for_single_wallet()
    {
        $this->service->create($this->betRecord, $this->transactionLogId);

        $this->assertDatabaseHas('total_bet_records', [
            'member_id' => $this->memberId,
            'vendor_id' => $this->vendorId,
            'bet_record_id' => $this->betRecord->id,
            'reference_no' => $this->betRecord->bet_id,
            'bet' => $this->betRecord->bet,
            'valid_bet' => $this->betRecord->valid_bet,
            'win_lose' => $this->betRecord->win_lose,
        ]);

        $totalBetRecord = TotalBetRecord::first();

        $this->assertDatabaseHas('total_bet_record_transaction_logs', [
            'transaction_log_id' => $this->transactionLogId,
            'total_bet_record_id' => $totalBetRecord->id,
        ]);
    }

    public function test_create_total_bet_record_for_multiple_wallet()
    {
        $service = new TotalBetRecordService($this->vendorId, $this->memberId, true);
        $service->create($this->betRecord, $this->transactionLogId);

        $this->assertDatabaseHas('total_bet_records', [
            'member_id' => $this->memberId,
            'vendor_id' => $this->vendorId,
            'bet_record_id' => $this->betRecord->id,
            'reference_no' => $this->betRecord->bet_id,
            'bet' => $this->betRecord->bet,
            'valid_bet' => $this->betRecord->valid_bet,
            'win_lose' => $this->betRecord->win_lose,
        ]);

        $totalBetRecord = TotalBetRecord::first();

        $this->assertDatabaseHas('total_bet_record_wallet_transaction_logs', [
            'transaction_log_id' => $this->transactionLogId,
            'total_bet_record_id' => $totalBetRecord->id,
        ]);
    }

    public function test_create_total_bet_record_with_custom_reference_no()
    {
        $customReferenceNo = 'custom_ref_no';
        $this->service->create($this->betRecord, $this->transactionLogId, ['reference_no' => $customReferenceNo]);

        $this->assertDatabaseHas('total_bet_records', [
            'reference_no' => $customReferenceNo,
        ]);
    }

    public function test_create_total_bet_record_verifies_transaction_log_relation_is_created()
    {
        // 建立正常的 total bet record
        $this->service->create($this->betRecord, $this->transactionLogId);

        $totalBetRecord = TotalBetRecord::first();

        // 驗證關聯記錄確實被建立
        $this->assertDatabaseHas('total_bet_record_transaction_logs', [
            'transaction_log_id' => $this->transactionLogId,
            'total_bet_record_id' => $totalBetRecord->id,
        ]);

        // 驗證關聯數量正確
        $this->assertEquals(1, \App\Models\TotalBetRecordTransactionLog::count());
    }

    #[RunInSeparateProcess]
    public function test_create_total_bet_record_create_or_update_same_bet_record()
    {
        $this->betRecord->win_lose = 100;
        $this->betRecord->updated_at = '2023-01-01 00:00:00';

        $this->service->createOrUpdate($this->betRecord, $this->betRecord, $this->transactionLogId, 'test');

        $this->assertDatabaseHas('total_bet_records', [
            'bet_record_id' => $this->betRecord->id,
            'win_lose' => $this->betRecord->getWinLose(),
        ]);
    }
}
