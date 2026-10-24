<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Data\Vendor\MerchantAccountPoolResultData;
use App\Data\Vendor\VendorAccountPoolResultData;
use App\Helpers\Common;
use App\Services\VendorAccountPoolService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 預產遊戲帳號供用戶綁定（純調度殼）
 *
 * 業務邏輯收斂於 VendorAccountPoolService，本命令依 option 調用對應語意方法並渲染輸出：
 * 無 flag → dispatchQueueReplenish()、--direct → replenishNow()、--dry-run → previewReplenish()。
 * 排程全量範圍由 driver 實作 ShouldScheduleAccountCreation 決定；
 * --vendor-id 指哪打哪（driver 只需 SupportsAccountCreation）。
 *
 * php artisan create_vendor_account                            # 排程模式：逐商派發 queue
 * php artisan create_vendor_account --vendor-id=233 --direct   # 同步單商（後台救援用）
 * php artisan create_vendor_account --dry-run                  # 乾跑（DB 回滾；已送出三方的建號請求不會撤銷）
 *
 * @knowledge rules/vendor-account-pool
 */
class CreateVendorAccount extends Command
{
    protected $signature = 'create_vendor_account
                            {--vendor-id= : 指定要處理的遊戲商 ID}
                            {--direct : 同步執行不派發 queue（後台救援用）}
                            {--dry-run : 乾跑模式，不寫入資料庫}';

    protected $description = '預產遊戲帳號供用戶綁定';

    public function __construct(private readonly VendorAccountPoolService $poolService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $vendorId = $this->option('vendor-id') !== null ? (int) $this->option('vendor-id') : null;

        try {
            if ($this->option('dry-run')) {
                $this->warn('=== 乾跑模式：資料庫變更將回滾（已送出三方的建號請求不會撤銷）===');
                $this->renderVendorResults($this->poolService->previewReplenish($vendorId));
                $this->info('=== 乾跑模式結束：所有變更已回滾 ===');
            } elseif ($this->option('direct')) {
                $this->renderVendorResults($this->poolService->replenishNow($vendorId));
            } else {
                $this->renderDispatched($this->poolService->dispatchQueueReplenish($vendorId));
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            Log::error('CreateVendorAccount 錯誤', [
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{vendor_id: int, vendor_name: string, delay: int, error?: string}>  $dispatched
     */
    private function renderDispatched(array $dispatched): void
    {
        if ($dispatched === []) {
            $this->warn('沒有可處理的遊戲商');

            return;
        }

        $failed = 0;

        foreach ($dispatched as $item) {
            if (isset($item['error'])) {
                $failed++;
                $this->error("派發失敗: {$item['vendor_name']} (ID: {$item['vendor_id']})，{$item['error']}");

                continue;
            }

            $this->line("已派發: {$item['vendor_name']} (ID: {$item['vendor_id']})"
                . ($item['delay'] > 0 ? "，延遲 {$item['delay']} 秒" : ''));
        }

        $this->info('共派發 ' . (count($dispatched) - $failed) . ' 個帳號池補充任務' . ($failed > 0 ? "，{$failed} 個派發失敗" : ''));
    }

    /**
     * @param  list<VendorAccountPoolResultData>  $results
     */
    private function renderVendorResults(array $results): void
    {
        if ($results === []) {
            $this->warn('沒有可處理的遊戲商');

            return;
        }

        foreach ($results as $result) {
            $this->newLine();
            $this->info("處理遊戲商: {$result->vendorName} (ID: {$result->vendorId})");

            if ($result->error !== null) {
                $this->error("⚠ {$result->error}");

                continue;
            }

            foreach ($result->merchantResults as $merchant) {
                $this->renderMerchantResult($merchant);
            }

            $this->info("完成遊戲商: {$result->vendorName} (ID: {$result->vendorId})");
        }
    }

    private function renderMerchantResult(MerchantAccountPoolResultData $merchant): void
    {
        $this->line("▶ 商戶 vendor_info_id {$merchant->vendorInfoId}: 未綁定 {$merchant->unboundCount}／安全庫存 {$merchant->preparedAccountNumber}");

        if ($merchant->lackNumber === 0) {
            $this->info('  ✓ 帳號數量充足，無需補齊');

            return;
        }

        $this->warn("  需補齊帳號數量: {$merchant->lackNumber}");

        if ($merchant->createdUsernames !== []) {
            $this->info('  ✓ 已補建 ' . count($merchant->createdUsernames) . ' 個帳號: ' . implode(', ', $merchant->createdUsernames));
        }

        if ($merchant->failedUsernames !== []) {
            $this->error('  ✗ 建立失敗 ' . count($merchant->failedUsernames) . ' 個: ' . implode(', ', $merchant->failedUsernames));
        }
    }
}
