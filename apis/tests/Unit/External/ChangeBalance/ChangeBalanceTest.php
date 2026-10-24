<?php

declare(strict_types=1);

namespace Tests\Unit\External\ChangeBalance;

use App\Enums\Transaction\TransactionEnum;
use App\Enums\WalletEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\TransactionLog;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\ClotswBetRecord;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * ChangeBalance 單一錢包餘額變動測試
 *
 * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php
 */
class ChangeBalanceTest extends TestCase
{
    protected MemberWallet $rollingChipWallet;

    protected Member $member;

    protected Agent $agent;

    protected TestController $service;

    protected WalletService $walletService;

    protected VendorInfo $vendorInfo;

    protected string $betRecordClass = ClotswBetRecord::class;

    protected string $initialBalance = '1000';

    protected string $initialRollingChip = '1000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'balance' => $this->initialBalance,
        ]);

        $this->rollingChipWallet = MemberWallet::factory()->create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP,
            'balance' => $this->initialRollingChip,
        ]);

        $this->vendorInfo = $this->createVendorInfo();

        $this->service = App::make(TestController::class);
        $this->service->setVendorInfoTest($this->vendorInfo);
        $this->walletService = App::make(WalletService::class);
    }

    /**
     * 測試自動save投注記錄
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_save_bet_record
     */
    public function test_save_bet_record()
    {
        $autoSave = true;
        $enableRollingChip = false;
        $bet = 100;
        $win = 0;
        $betRecord = $this->createBetRecord($bet, $win);
        $walletDto = $this->walletService->getMemberLockedWallets($this->member, $enableRollingChip);
        $this->service->changeBalanceTest($this->member, $walletDto, null, $betRecord, $autoSave);

        $existBetRecord = $this->betRecordClass::query()->where('bet_id', $betRecord->bet_id)->first();
        $this->assertNotNull($existBetRecord);
    }

    /**
     * 測試不自動save投注記錄
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_no_save_bet_record
     */
    public function test_no_save_bet_record()
    {
        $autoSave = false;
        $enableRollingChip = false;
        $bet = 100;
        $win = 0;
        $betRecord = $this->createBetRecord($bet, $win);
        $walletDto = $this->walletService->getMemberLockedWallets($this->member, $enableRollingChip);
        $this->service->changeBalanceTest($this->member, $walletDto, null, $betRecord, $autoSave);

        $existBetRecord = $this->betRecordClass::query()->where('bet_id', $betRecord->bet_id)->first();
        $this->assertNull($existBetRecord);
    }

    /**
     * 不啟用禮碼錢包的投注
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_bet
     */
    public function test_bet(): ClotswBetRecord
    {
        $enableRollingChip = false;
        $bet = 100;
        $win = 0;
        $betRecord = $this->createBetRecord($bet, $win);
        $walletDto = $this->walletService->getMemberLockedWallets($this->member, $enableRollingChip);
        $this->service->changeBalanceTest($this->member, $walletDto, null, $betRecord);

        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        $assertBalance = Common::numSub($this->initialBalance, $bet, 4);
        $assertRollingChip = $this->initialRollingChip;

        $this->assertEquals(Common::numCompare($this->member->balance, $assertBalance), 0);
        $this->assertEquals(Common::numCompare($this->rollingChipWallet->balance, $assertRollingChip), 0);

        $this->assertEquals(Common::numCompare($betRecord->getRollingChip(), 0), 0);

        return $betRecord;
    }

    /**
     * 不啟用禮碼錢包的投注+派彩
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_bet_and_settle
     */
    public function test_bet_and_settle(): ClotswBetRecord
    {
        $oldBetRecord = $this->test_bet();
        $betRecord = clone $oldBetRecord;
        $betRecord->win = 200;
        $enableRollingChip = false;
        $walletDto = $this->walletService->getMemberLockedWallets($this->member, $enableRollingChip);
        $this->service->changeBalanceTest($this->member, $walletDto, $oldBetRecord, $betRecord);

        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        $assertBalance = Common::numSub($this->initialBalance, $betRecord->getBet(), 4);
        $assertBalance = Common::numAdd($assertBalance, $betRecord->getWin(), 4);
        $assertRollingChip = $this->initialRollingChip;

        $this->assertEquals(Common::numCompare($this->member->balance, $assertBalance), 0);
        $this->assertEquals(Common::numCompare($this->rollingChipWallet->balance, $assertRollingChip), 0);

        $this->assertEquals(Common::numCompare($betRecord->getRollingChip(), 0), 0);

        return $betRecord;
    }

    /**
     * 不啟用禮碼錢包的投注+派彩+取消
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_bet_and_settle_and_refund
     */
    public function test_bet_and_settle_and_refund(): ClotswBetRecord
    {
        $oldBetRecord = $this->test_bet_and_settle();
        $betRecord = clone $oldBetRecord;
        $betRecord->bet = 0;
        $betRecord->win = 0;
        $enableRollingChip = false;
        $walletDto = $this->walletService->getMemberLockedWallets($this->member, $enableRollingChip);
        $this->service->changeBalanceTest($this->member, $walletDto, $oldBetRecord, $betRecord);

        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        $assertBalance = $this->initialBalance;
        $assertRollingChip = $this->initialRollingChip;

        $this->assertEquals(Common::numCompare($this->member->balance, $assertBalance), 0);
        $this->assertEquals(Common::numCompare($this->rollingChipWallet->balance, $assertRollingChip), 0);

        $this->assertEquals(Common::numCompare($betRecord->getRollingChip(), 0), 0);

        return $betRecord;
    }

    /**
     * 不啟用禮碼錢包的投注+取消
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_bet_and_refund
     */
    public function test_bet_and_refund(): ClotswBetRecord
    {
        $oldBetRecord = $this->test_bet();
        $betRecord = clone $oldBetRecord;
        $betRecord->bet = 0;
        $enableRollingChip = false;
        $walletDto = $this->walletService->getMemberLockedWallets($this->member, $enableRollingChip);
        $this->service->changeBalanceTest($this->member, $walletDto, $oldBetRecord, $betRecord);

        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        $assertBalance = $this->initialBalance;
        $assertRollingChip = $this->initialRollingChip;

        $this->assertEquals(Common::numCompare($this->member->balance, $assertBalance), 0);
        $this->assertEquals(Common::numCompare($this->rollingChipWallet->balance, $assertRollingChip), 0);

        $this->assertEquals(Common::numCompare($betRecord->getRollingChip(), 0), 0);

        return $betRecord;
    }

    /**
     * 啟用禮碼錢包的投注
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_bet_with_rolling_chip
     */
    public function test_bet_with_rolling_chip(): ClotswBetRecord
    {
        $enableRollingChip = true;
        $bet = 100;
        $win = 0;
        $betRecord = $this->createBetRecord($bet, $win);
        $walletDto = $this->walletService->getMemberLockedWallets($this->member, $enableRollingChip);
        $this->service->changeBalanceTest($this->member, $walletDto, null, $betRecord);

        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        $assertBalance = $this->initialBalance;
        $assertRollingChip = Common::numSub($this->initialRollingChip, $bet, 4);

        $this->assertEquals(Common::numCompare($this->member->balance, $assertBalance), 0);
        $this->assertEquals(Common::numCompare($this->rollingChipWallet->balance, $assertRollingChip), 0);

        $this->assertEquals(Common::numCompare($betRecord->getRollingChip(), $bet), 0);

        return $betRecord;
    }

    /**
     * 啟用禮碼錢包的投注+派彩
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_bet_with_rolling_chip_and_settle
     */
    public function test_bet_with_rolling_chip_and_settle(): ClotswBetRecord
    {
        $oldBetRecord = $this->test_bet_with_rolling_chip();
        $betRecord = clone $oldBetRecord;
        $betRecord->win = 200;
        $enableRollingChip = true;
        $walletDto = $this->walletService->getMemberLockedWallets($this->member, $enableRollingChip);
        $this->service->changeBalanceTest($this->member, $walletDto, $oldBetRecord, $betRecord);

        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        $assertBalance = Common::numAdd($this->initialBalance, $betRecord->getWin(), 4);
        $assertRollingChip = Common::numSub($this->initialRollingChip, $betRecord->getBet(), 4);

        $this->assertEquals(Common::numCompare($this->member->balance, $assertBalance), 0);
        $this->assertEquals(Common::numCompare($this->rollingChipWallet->balance, $assertRollingChip), 0);

        $this->assertEquals(Common::numCompare($betRecord->getRollingChip(), $betRecord->getBet()), 0);

        return $betRecord;
    }

    /**
     * 啟用禮碼錢包的投注+派彩+取消
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_bet_with_rolling_chip_and_settle_and_refund
     */
    public function test_bet_with_rolling_chip_and_settle_and_refund(): ClotswBetRecord
    {
        $oldBetRecord = $this->test_bet_with_rolling_chip_and_settle();
        $betRecord = clone $oldBetRecord;
        $betRecord->bet = 0;
        $betRecord->win = 0;
        $enableRollingChip = true;
        $walletDto = $this->walletService->getMemberLockedWallets($this->member, $enableRollingChip);
        $this->service->changeBalanceTest($this->member, $walletDto, $oldBetRecord, $betRecord);

        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        $assertBalance = $this->initialBalance;
        $assertRollingChip = $this->initialRollingChip;

        $this->assertEquals(Common::numCompare($this->member->balance, $assertBalance), 0);
        $this->assertEquals(Common::numCompare($this->rollingChipWallet->balance, $assertRollingChip), 0);

        $this->assertEquals(Common::numCompare($betRecord->getRollingChip(), 0), 0);

        return $betRecord;
    }

    /**
     * 啟用禮碼錢包的投注+取消
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_bet_with_rolling_chip_and_settle
     */
    public function test_bet_with_rolling_chip_and_refund(): ClotswBetRecord
    {
        $oldBetRecord = $this->test_bet_with_rolling_chip();
        $betRecord = clone $oldBetRecord;
        $betRecord->bet = 0;
        $enableRollingChip = true;
        $walletDto = $this->walletService->getMemberLockedWallets($this->member, $enableRollingChip);
        $this->service->changeBalanceTest($this->member, $walletDto, $oldBetRecord, $betRecord);

        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        $assertBalance = $this->initialBalance;
        $assertRollingChip = $this->initialRollingChip;

        $this->assertEquals(Common::numCompare($this->member->balance, $assertBalance), 0);
        $this->assertEquals(Common::numCompare($this->rollingChipWallet->balance, $assertRollingChip), 0);

        $this->assertEquals(Common::numCompare($betRecord->getRollingChip(), 0), 0);

        return $betRecord;
    }

    /**
     * 啟用禮碼錢包的投注
     * 禮碼不足的情況下，真金錢包會被扣除相應的金額
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_bet_with_rolling_chip_and_cash
     */
    public function test_bet_with_rolling_chip_and_cash(): ClotswBetRecord
    {
        $enableRollingChip = true;
        $bet = 1100;
        $win = 0;
        $betRecord = $this->createBetRecord($bet, $win);
        $walletDto = $this->walletService->getMemberLockedWallets($this->member, $enableRollingChip);
        $this->service->changeBalanceTest($this->member, $walletDto, null, $betRecord);

        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        $assertBalance = $this->initialBalance;
        $assertRollingChip = Common::numSub($this->initialRollingChip, $bet, 4);
        if ($assertRollingChip < 0) {
            $assertBalance = Common::numAdd($assertBalance, $assertRollingChip, 4);
            $assertRollingChip = 0;
        }

        $this->assertEquals(Common::numCompare($this->member->balance, $assertBalance), 0);
        $this->assertEquals(Common::numCompare($this->rollingChipWallet->balance, $assertRollingChip), 0);

        $this->assertEquals(Common::numCompare($betRecord->getRollingChip(), $this->initialRollingChip), 0);

        return $betRecord;
    }

    /**
     * 啟用禮碼錢包的投注 + 派彩
     * 禮碼不足的情況下，真金錢包會被扣除相應的金額
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_bet_with_rolling_chip_and_cash_and_settle
     */
    public function test_bet_with_rolling_chip_and_cash_and_settle(): ClotswBetRecord
    {
        $oldBetRecord = $this->test_bet_with_rolling_chip_and_cash();
        $betRecord = clone $oldBetRecord;
        $betRecord->win = 200;
        $enableRollingChip = true;
        $walletDto = $this->walletService->getMemberLockedWallets($this->member, $enableRollingChip);
        $this->service->changeBalanceTest($this->member, $walletDto, $oldBetRecord, $betRecord);

        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        // 投注
        $assertBalance = $this->initialBalance;
        $assertRollingChip = Common::numSub($this->initialRollingChip, $betRecord->bet, 4);
        if ($assertRollingChip < 0) {
            $assertBalance = Common::numAdd($assertBalance, $assertRollingChip, 4);
            $assertRollingChip = 0;
        }

        // 派彩
        $assertBalance = Common::numAdd($assertBalance, $betRecord->win, 4);

        $this->assertEquals(Common::numCompare($this->member->balance, $assertBalance), 0);
        $this->assertEquals(Common::numCompare($this->rollingChipWallet->balance, $assertRollingChip), 0);

        $this->assertEquals(Common::numCompare($betRecord->getRollingChip(), $this->initialRollingChip), 0);

        return $betRecord;
    }

    /**
     * 啟用禮碼錢包的投注 + 派彩 + 取消
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_bet_with_rolling_chip_and_cash_and_settle_and_refund
     */
    public function test_bet_with_rolling_chip_and_cash_and_settle_and_refund(): ClotswBetRecord
    {
        $oldBetRecord = $this->test_bet_with_rolling_chip_and_cash_and_settle();
        $betRecord = clone $oldBetRecord;
        $betRecord->bet = 0;
        $betRecord->win = 0;
        $enableRollingChip = true;
        $walletDto = $this->walletService->getMemberLockedWallets($this->member, $enableRollingChip);
        $this->service->changeBalanceTest($this->member, $walletDto, $oldBetRecord, $betRecord);

        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        $assertBalance = $this->initialBalance;
        $assertRollingChip = $this->initialRollingChip;

        $this->assertEquals(Common::numCompare($this->member->balance, $assertBalance), 0);
        $this->assertEquals(Common::numCompare($this->rollingChipWallet->balance, $assertRollingChip), 0);

        $this->assertEquals(Common::numCompare($betRecord->getRollingChip(), 0), 0);

        return $betRecord;
    }

    /**
     * 啟用禮碼錢包的投注 + 取消
     * 禮碼不足的情況下，真金錢包會被扣除相應的金額
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_bet_with_rolling_chip_and_cash_and_refund
     */
    public function test_bet_with_rolling_chip_and_cash_and_refund(): ClotswBetRecord
    {
        $oldBetRecord = $this->test_bet_with_rolling_chip_and_cash();
        $betRecord = clone $oldBetRecord;
        $betRecord->bet = 0;
        $enableRollingChip = true;
        $walletDto = $this->walletService->getMemberLockedWallets($this->member, $enableRollingChip);
        $this->service->changeBalanceTest($this->member, $walletDto, $oldBetRecord, $betRecord);

        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        $assertBalance = $this->initialBalance;
        $assertRollingChip = $this->initialRollingChip;

        $this->assertEquals(Common::numCompare($this->member->balance, $assertBalance), 0);
        $this->assertEquals(Common::numCompare($this->rollingChipWallet->balance, $assertRollingChip), 0);

        $this->assertEquals(Common::numCompare($betRecord->getRollingChip(), 0), 0);

        return $betRecord;
    }

    /**
     * 派彩變大（多段累進派彩）：仍寫 27 SETTLE 並延用同一筆 log
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_progressive_payout_writes_settle_type
     */
    public function test_progressive_payout_writes_settle_type(): void
    {
        $oldBetRecord = $this->test_bet_and_settle();
        $previousLogId = TransactionLog::query()->where('member_id', $this->member->id)->max('id');
        $additionalWin = '150';
        $betRecord = clone $oldBetRecord;
        $betRecord->win = Common::numAdd($oldBetRecord->win, $additionalWin, 4);
        $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);

        $walletDto = $this->walletService->getMemberLockedWallets($this->member, false);
        $transactionLogId = $this->service->changeBalanceTest($this->member, $walletDto, $oldBetRecord, $betRecord);

        $transactionLog = TransactionLog::find($transactionLogId);
        $this->assertNotNull($transactionLog);
        $this->assertSame(TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value, $transactionLog->type);
        // 延用同一筆：回傳上一筆 log id
        $this->assertSame($previousLogId, $transactionLogId);
    }

    /**
     * 派彩被改小：以前推斷為 29 CHANGE，新設計下不再推斷，歸 27 SETTLE 並延用
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_payout_decrease_writes_settle_type
     */
    public function test_payout_decrease_writes_settle_type(): void
    {
        $oldBetRecord = $this->test_bet_and_settle();
        $previousLogId = TransactionLog::query()->where('member_id', $this->member->id)->max('id');
        $reduction = '150';
        $betRecord = clone $oldBetRecord;
        $betRecord->win = Common::numSub($oldBetRecord->win, $reduction, 4);
        $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);

        $walletDto = $this->walletService->getMemberLockedWallets($this->member, false);
        $transactionLogId = $this->service->changeBalanceTest($this->member, $walletDto, $oldBetRecord, $betRecord);

        $transactionLog = TransactionLog::find($transactionLogId);
        $this->assertNotNull($transactionLog);
        $this->assertSame(TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value, $transactionLog->type);
        $this->assertSame($previousLogId, $transactionLogId);
    }

    /**
     * 派彩全沖銷至 0：以前推斷為 29 CHANGE，新設計下歸 27 SETTLE 並延用
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_payout_fully_clawed_back_writes_settle_type
     */
    public function test_payout_fully_clawed_back_writes_settle_type(): void
    {
        $oldBetRecord = $this->test_bet_and_settle();
        $previousLogId = TransactionLog::query()->where('member_id', $this->member->id)->max('id');
        $betRecord = clone $oldBetRecord;
        $betRecord->win = 0;
        $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);

        $walletDto = $this->walletService->getMemberLockedWallets($this->member, false);
        $transactionLogId = $this->service->changeBalanceTest($this->member, $walletDto, $oldBetRecord, $betRecord);

        $transactionLog = TransactionLog::find($transactionLogId);
        $this->assertNotNull($transactionLog);
        $this->assertSame(TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value, $transactionLog->type);
        $this->assertSame($previousLogId, $transactionLogId);
    }

    /**
     * Vendor 退回 bet（bet 變 0）：以前推斷為 28 CANCEL，新設計下歸 27 SETTLE 並延用
     * 若 vendor 真的要 CANCEL 語意，應在 controller 內顯式 setType(SEAMLESS_WALLET_CANCEL)
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_bet_zeroed_writes_settle_type
     */
    public function test_bet_zeroed_writes_settle_type(): void
    {
        $oldBetRecord = $this->test_bet();
        $previousLogId = TransactionLog::query()->where('member_id', $this->member->id)->max('id');
        $betRecord = clone $oldBetRecord;
        $betRecord->bet = 0;
        $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);

        $walletDto = $this->walletService->getMemberLockedWallets($this->member, false);
        $transactionLogId = $this->service->changeBalanceTest($this->member, $walletDto, $oldBetRecord, $betRecord);

        $transactionLog = TransactionLog::find($transactionLogId);
        $this->assertNotNull($transactionLog);
        $this->assertSame(TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value, $transactionLog->type);
        $this->assertSame($previousLogId, $transactionLogId);
    }

    /**
     * 整段 session（bet → settle → 累進派彩 → 派彩被改小 → bet 退回）只產生一筆 transaction_log
     * 驗證 transaction_logs.type 作為 session marker 的設計核心
     * php artisan test tests/Unit/External/ChangeBalance/ChangeBalanceTest.php --filter test_entire_session_accumulates_into_single_log
     */
    public function test_entire_session_accumulates_into_single_log(): void
    {
        $beforeCount = TransactionLog::query()->where('member_id', $this->member->id)->count();

        $br1 = $this->createBetRecord(bet: 100, win: 0);
        $walletDto = $this->walletService->getMemberLockedWallets($this->member, false);
        $log1Id = $this->service->changeBalanceTest($this->member, $walletDto, null, $br1);

        $br2 = clone $br1;
        $br2->win = 200;
        $log2Id = $this->service->changeBalanceTest($this->member, $walletDto, $br1, $br2);

        $br3 = clone $br2;
        $br3->win = 350;
        $log3Id = $this->service->changeBalanceTest($this->member, $walletDto, $br2, $br3);

        $br4 = clone $br3;
        $br4->win = 80;
        $br4->win_lose = Common::numSub($br4->win, $br4->bet, 4);
        $log4Id = $this->service->changeBalanceTest($this->member, $walletDto, $br3, $br4);

        $br5 = clone $br4;
        $br5->bet = 0;
        $br5->win_lose = Common::numSub($br5->win, $br5->bet, 4);
        $log5Id = $this->service->changeBalanceTest($this->member, $walletDto, $br4, $br5);

        $afterCount = TransactionLog::query()->where('member_id', $this->member->id)->count();

        // 整段 session 只新建 1 筆 transaction_log
        $this->assertSame(1, $afterCount - $beforeCount);
        // 五次 changeBalance 回傳同一筆 log id
        $this->assertSame($log1Id, $log2Id);
        $this->assertSame($log1Id, $log3Id);
        $this->assertSame($log1Id, $log4Id);
        $this->assertSame($log1Id, $log5Id);

        $log = TransactionLog::find($log1Id);
        $this->assertSame(TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value, $log->type);
    }

    protected function createBetRecord(int|float|string $bet, int|float|string $win): ClotswBetRecord
    {
        $betRecord = new $this->betRecordClass([
            'bet_id' => uniqid(),
            'username' => $this->member->username,
            'game_code' => 'unit_test_game',
            'bet' => $bet,
            'valid_bet' => $bet,
            'win' => $win,
            'win_lose' => Common::numSub($win, $bet, 4),
            'rolling_chip' => 0,
            'info' => '[]',
            'bet_time' => '1970-01-01 00:00:00',
            'payout_time' => '1970-01-01 00:00:00',
        ]);

        return $betRecord;
    }

    protected function createVendorInfo(): VendorInfo
    {
        // INSERT INTO `xsgcasino`.`vendor_infos` (`id`, `name`, `type`, `vendor_id`, `currency`, `rate`, `transfer_min_amount`, `info`, `prepared_account_number`, `account_prefix`, `cost`, `status`, `rolling_chip_status`, `created_at`, `updated_at`, `admin`, `deposit_limit`) VALUES (311, 'Clot Play SW', 0, 142, 'MYR', 1.00000, 0.01000, '{\"apiUrl\":\"https:\\/\\/example.invalid\\/api\\/MOGI\",\"operatorToken\":\"VW1C5858BD139962\",\"agentCode\":\"VWorld\"}', 10, 'devCLOTSW', 0.0000, 1, 1, '2025-03-10 09:27:40', '2025-04-10 02:28:29', 1, 0.0000);
        $vendorInfo = VendorInfo::create([
            'name' => 'Clot Play SW',
            'type' => 0,
            'vendor_id' => 142,
            'currency' => 'MYR',
            'rate' => 1.00000,
            'transfer_min_amount' => 0.01000,
            'info' => '{\"apiUrl\":\"https:\\/\\/example.invalid\\/api\\/MOGI\",\"operatorToken\":\"VW1C5858BD139962\",\"agentCode\":\"VWorld\"}',
            'prepared_account_number' => 10,
            'account_prefix' => 'devCLOTSW',
            'cost' => 0.0000,
            'status' => 1,
            'rolling_chip_status' => 1,
            'admin' => 1,
            'deposit_limit' => 0.0000,
        ]);

        return $vendorInfo;
    }
}
