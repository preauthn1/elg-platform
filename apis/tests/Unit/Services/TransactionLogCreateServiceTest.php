<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\TransactionLog;
use App\Models\VendorInfo;
use App\Models\WalletTransactionLog;
use App\Services\TransactionLogCreateService;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * TransactionLogCreateService 測試
 *
 * php artisan test tests/Unit/Services/TransactionLogCreateServiceTest.php
 */
class TransactionLogCreateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = 'tlc-' . uniqid('', true) . '.local';
    }

    public function test_create_inserts_transaction_log_when_no_vendor_info(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'tlc_single_' . uniqid()]);

        $service = new TransactionLogCreateService(member: $member);
        $service->setType(TransactionEnum::ORDER_DEPOSIT->value);

        $before = TransactionLog::query()->where('member_id', $member->id)->count();
        $log = $service->create('10.0000', '100.0000', '110.0000');
        $after = TransactionLog::query()->where('member_id', $member->id)->count();

        $this->assertInstanceOf(TransactionLog::class, $log);
        $this->assertSame($before + 1, $after);
        $this->assertSame(TransactionEnum::ORDER_DEPOSIT->value, $log->type);
        $this->assertSame(1000, $log->status);
        $this->assertSame('10.0000', (string) $log->point);
    }

    public function test_create_inserts_wallet_transaction_log_when_multiple_wallet(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'tlc_mw_' . uniqid()]);

        $service = (new TransactionLogCreateService(member: $member))->isMultipleWallet();
        $service->setType(TransactionEnum::DEPOSIT->value);

        $before = WalletTransactionLog::query()->where('member_id', $member->id)->count();
        $log = $service->create('5.0000', '10.0000', '15.0000');
        $after = WalletTransactionLog::query()->where('member_id', $member->id)->count();

        $this->assertInstanceOf(WalletTransactionLog::class, $log);
        $this->assertSame($before + 1, $after);
        $this->assertSame(TransactionEnum::DEPOSIT->value, $log->type);
        $this->assertSame(1000, $log->status);
    }

    #[TestDox('合併進行中紀錄時以資料庫現值累加，不用手上那份可能已過期的內容')]
    public function test_merge_recomputes_point_from_current_database_value(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'tlc_merge_' . uniqid()]);
        $vendor = $this->createBasicVendorGames($agent, VendorIdEnum::PGSSW->value);
        $vendorInfo = VendorInfo::query()->where('vendor_id', $vendor->id)->firstOrFail();
        $vendorInfo->rate = 1.0;
        $vendorInfo->save();

        // 投注寫下一筆「遊戲進行中」的現金紀錄，金額 5
        $log = TransactionLog::query()->create([
            'transaction_no' => 'TLC-' . uniqid(),
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'vendor_id' => $vendorInfo->vendor_id,
            'order_id' => 0,
            'type' => TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value,
            'point' => '5.0000',
            'tax' => '0.0000',
            'vendor_point' => '5.0000',
            'rate' => '1.0000',
            'before_balance' => '105.0000',
            'balance' => '100.0000',
            'vendor_balance' => '100.0000',
            'status' => TransactionStatusEnum::STATUS_GAMING->value,
            'result' => '0',
            'operator' => $member->username,
            'admin' => 0,
        ]);

        // 這個物件停在金額 5 的當下，之後不再更新——等同正式環境「等會員鎖之前就把資料讀進來」的那份舊內容
        $staleLog = TransactionLog::query()->findOrFail($log->id);

        // 另一邊把同一列累加到 15（正式環境是投注請求拿到鎖後寫進去的）
        TransactionLog::query()->where('id', $log->id)->update(['point' => '15.0000']);

        $service = new TransactionLogCreateService($member, $vendorInfo);
        $merge = new \ReflectionMethod($service, 'updateOrLockTransactionLog');
        $merged = $merge->invoke($service, $staleLog, '-10.0000', '110.0000');

        $this->assertFalse($merged, '同一遊戲商且未結算，應該併進既有那筆而不是另開一筆');
        $this->assertSame(
            '5.0000',
            (string) TransactionLog::query()->where('id', $log->id)->value('point'),
            '應以資料庫現值 15 減去 10 得 5；若用手上那份過期的 5 去減，會寫成 -5 並把另一邊寫入的 10 蓋掉'
        );
    }
}
