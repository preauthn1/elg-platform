<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Vendor;
use App\Models\VendorMaintenances;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 逐商批次作業服務基類
 *
 * 收攏「對可用遊戲商逐一處理」的共用底座：availableVendors() 過濾與商戶預載、
 * loopVendors() per-vendor 錯誤隔離、previewLoop() 乾跑回滾；
 * 「排程全量該掃哪些商」由子類 applySchedulableVendorScope() 決定。
 *
 * @knowledge rules/vendor-account-pool
 */
abstract class AbstractVendorBatchService
{
    public function __construct(protected readonly VendorService $vendorService) {}

    /**
     * 排程全量模式下限制可處理的遊戲商範圍（各作業自己的業務規則）
     *
     * @param  Builder<Vendor>  $query
     */
    abstract protected function applySchedulableVendorScope(Builder $query): void;

    /**
     * 維護中的遊戲商是否排除（子類覆寫）：維護只擋玩家下注，
     * 不依賴玩家在線的作業（如拉已結算注單）可覆寫為 false 照常處理
     */
    protected function excludesMaintenanceVendors(): bool
    {
        return true;
    }

    /**
     * 啟用遊戲商的共用查詢起點：排除停用清單（disabled_vendors.php）、預載啟用商戶
     *
     * 啟用商戶以 with() 預載：後續迭代不會 N+1，也不會 lazy load 混進停用商戶。
     * 批次作業（availableVendors）與唯讀查詢（如帳號池 inventory）共用；
     * 範圍語意（排程 scope、維護中排除與否）由呼叫端各自疊加，不在此收斂。
     *
     * @return Builder<Vendor>
     */
    protected function enabledVendorsQuery(): Builder
    {
        /** @var list<int> $deactivateIds 停用的遊戲商（單一事實來源：database/data/disabled_vendors.php） */
        $deactivateIds = require database_path('data/disabled_vendors.php');

        return Vendor::query()
            ->where('status', 1)
            ->whereNotIn('id', $deactivateIds)
            ->whereHas('vendorInfos', fn ($q) => $q->where('status', 1))
            ->with(['vendorInfos' => fn ($q) => $q->where('status', 1)])
            ->select('id', 'name', 'code', 'valid_bet_rate');
    }

    /**
     * 取得可處理的遊戲商（指定 vendor id 時指哪打哪，仍排除停用清單與維護中）
     *
     * @return Collection<int, Vendor>
     */
    public function availableVendors(?int $specificVendorId = null): Collection
    {
        $maintenanceIds = $this->excludesMaintenanceVendors() ? $this->maintenanceVendorIds() : [];

        return $this->enabledVendorsQuery()
            ->when($specificVendorId !== null, fn ($q) => $q->where('id', $specificVendorId))
            ->when($specificVendorId === null, fn ($q) => $this->applySchedulableVendorScope($q))
            ->when($maintenanceIds, fn ($q) => $q->whereNotIn('id', $maintenanceIds))
            ->get();
    }

    /**
     * 逐商迴圈＋per-vendor 錯誤隔離（單商失敗經 onError 轉為結果，繼續下一商）
     *
     * @template T
     *
     * @param  Closure(Vendor): T  $callback
     * @param  Closure(Vendor, Throwable): T  $onError  產生失敗結果並負責記 log
     * @return list<T>
     */
    protected function loopVendors(Closure $callback, Closure $onError, ?int $specificVendorId = null): array
    {
        $results = [];

        foreach ($this->availableVendors($specificVendorId) as $vendor) {
            try {
                $results[] = $callback($vendor);
            } catch (Throwable $e) {
                $results[] = $onError($vendor, $e);
            }
        }

        return $results;
    }

    /**
     * 乾跑：同 loopVendors，但整趟包事務、結束一律回滾
     *
     * @template T
     *
     * @param  Closure(Vendor): T  $callback
     * @param  Closure(Vendor, Throwable): T  $onError
     * @return list<T>
     */
    protected function previewLoop(Closure $callback, Closure $onError, ?int $specificVendorId = null): array
    {
        DB::beginTransaction();

        try {
            return $this->loopVendors($callback, $onError, $specificVendorId);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * 取得維護中的遊戲商 ID
     *
     * @return list<int>
     */
    protected function maintenanceVendorIds(): array
    {
        $now = now()->toDateTimeString();

        return VendorMaintenances::query()
            ->where('status', 1)
            ->where('type', 1)
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now)
            ->pluck('vendor_id')
            ->all();
    }
}
