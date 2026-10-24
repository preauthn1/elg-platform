<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Models\Agent;
use App\Models\Member;
use App\Models\Param;
use App\Models\VendorMaintenance;
use App\Models\VendorRequestFail;
use App\Services\BotNotifyService;
use App\Services\VendorService;
use App\Vendors\AbstractTransferWallet;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 檢測暫停轉點的轉帳錢包遊戲商是否已恢復可取餘額。
 *
 * 流程說明：
 * 1. 從 vendor_request_fails 取出待處理紀錄（status 0 或 1），略過單錢包遊戲商。
 * 2. 兩段式探測（避免個別會員自身因素誤掛維護）：
 *    a. 先以 vendor_request_fails.member_id 作為 driver 探測帳號呼叫 getBalance()。
 *    b. 會員探測失敗時，改用該總代（general_agent_id）的 vendor_detect 探針 Member 再探一次；
 *       找不到探針帳號或探針也失敗時，才視為三方真的故障。
 * 3. 任一探測成功：關閉自動維護、將 fail 標記為已恢復（status=2），首次恢復時發 Telegram 通知。
 * 4. 兩段皆失敗：開啟或新增維護（持續失敗時延長 end_time）、新建維護時發 Telegram 通知並將 fail 從 status=0 升級為 1。
 *
 * 維護來源以 vendor_maintenances.admin 區分（沿用舊後台 `CASE admin WHEN 0 THEN 'system'` 的慣例）：
 * - admin=0：本排程自建，可由排程延長／關閉。
 * - admin≠0：後台人員手動掛（或手動編輯過自動維護而接手），排程一律不得延長、不得關閉，
 *   且手動維護生效期間不重複建立自動維護，避免客服掛的維護被自動開回。
 *
 * 探針帳號來源：沿用舊系統 params(type=vendor_detect)，每筆 info 為 JSON、含 account（探針會員 username）；
 * 由 account → Member → member->agent->general_agent_id 推導出對應的總代，組成 {general_agent_id => Member} 對應表。
 *
 * vendor_request_fails.status：
 * - 0：停止轉帳（剛建立，尚未發送失敗通知）
 * - 1：已設定維護（已發送失敗通知）
 * - 2：恢復轉帳（餘額查詢成功）
 *
 * php artisan vendor_detect
 */
class VendorDetect extends Command
{
    protected $signature = 'vendor_detect';

    protected $description = '檢測遊戲商是否可取餘額';

    /**
     * 逐一檢測待恢復的遊戲商，彙整通知訊息後一次發送。
     */
    public function handle(VendorService $vendorService, BotNotifyService $botNotifyService): int
    {
        $this->info($this->description);

        try {
            $failedVendors = $this->getPendingFailedVendors();

            if ($failedVendors->isEmpty()) {
                $this->line('沒有待檢測的遊戲商');

                return Command::SUCCESS;
            }

            $message = '';

            // 預先解析各總代的 vendor_detect 探針 Member，避免迴圈內 N+1
            $probeMembers = $this->resolveGeneralAgentProbeMembers();

            foreach ($failedVendors as $row) {
                $vendorId = (int) $row->vendor_id;
                $generalAgentId = (int) $row->general_agent_id;

                $memberId = (int) $row->member_id;
                $member = Member::query()
                    ->with('agent')
                    ->find($memberId);

                if (!$member || !$member->agent) {
                    $this->warn("vendor {$vendorId} 找不到可用的 member (member_id={$memberId})");
                    Log::warning('vendor_detect member not found', [
                        'vendor_id' => $vendorId,
                        'member_id' => $memberId,
                    ]);

                    continue;
                }

                $agent = $member->agent;

                // 第一段：以 fail 紀錄的 member 探測（不再走前端 API）
                $balanceFetched = $this->tryFetchVendorBalance(
                    vendorService: $vendorService,
                    vendorId: $vendorId,
                    member: $member,
                );

                // 第二段：member 探測失敗時，改用該總代的 vendor_detect 探針 Member 再探一次，
                // 避免個別會員自身因素（如帳號異常）誤判三方故障而誤掛維護
                if (!$balanceFetched) {
                    $probeMember = $probeMembers->get($generalAgentId);

                    if ($probeMember instanceof Member) {
                        $balanceFetched = $this->tryFetchVendorBalance(
                            vendorService: $vendorService,
                            vendorId: $vendorId,
                            member: $probeMember,
                        );

                        Log::info('vendor_detect fallback probe attempted', [
                            'vendor_id' => $vendorId,
                            'general_agent_id' => $generalAgentId,
                            'probe_member_id' => $probeMember->id,
                            'balance_fetched' => $balanceFetched,
                        ]);
                    } else {
                        Log::info('vendor_detect no fallback probe member configured', [
                            'vendor_id' => $vendorId,
                            'general_agent_id' => $generalAgentId,
                        ]);
                    }
                }

                // 查詢代理線上目前有效的維護紀錄，並依 admin 區分自動／手動
                $maintenances = $this->findActiveMaintenances(
                    vendorId: $vendorId,
                    generalAgentId: $generalAgentId,
                );

                $autoMaintenance = $maintenances->first(
                    static fn (VendorMaintenance $maintenance): bool => (int) $maintenance->admin === 0,
                );
                $hasManualMaintenance = $maintenances->contains(
                    static fn (VendorMaintenance $maintenance): bool => (int) $maintenance->admin !== 0,
                );

                if ($balanceFetched) {
                    $this->info("vendor {$vendorId} ({$row->vendor?->name}): 餘額查詢成功");

                    $message .= $this->handleBalanceSuccess(
                        agentUsername: (string) $agent->username,
                        vendorName: (string) $row->vendor?->name,
                        vendorId: $vendorId,
                        generalAgentId: $generalAgentId,
                        previousStatus: (int) $row->status,
                        maintenance: $autoMaintenance,
                        hasManualMaintenance: $hasManualMaintenance,
                    );
                } else {
                    $this->error("vendor {$vendorId} ({$row->vendor?->name}): 餘額查詢失敗");

                    $message .= $this->handleBalanceFailure(
                        agent: $agent,
                        vendorName: (string) $row->vendor?->name,
                        vendorId: $vendorId,
                        generalAgentId: $generalAgentId,
                        maintenance: $autoMaintenance,
                        hasManualMaintenance: $hasManualMaintenance,
                    );
                }

                Log::info('vendor_detect checked vendor', [
                    'vendor_id' => $vendorId,
                    'member_id' => $member->id,
                    'balance_fetched' => $balanceFetched,
                ]);
            }

            // 有狀態變更才發 Telegram，避免每分鐘重複通知
            if ($message !== '') {
                $botNotifyService->channel('info')->send($message);
                $this->line($message);
            }

            Log::info('vendor_detect finished', ['message' => $message]);

            return Command::SUCCESS;
        } catch (QueryException $exception) {
            $info = $exception->errorInfo;
            $this->error(sprintf('%s : %s : %s', $info[0] ?? '', $info[2] ?? '', $exception->getMessage()));

            return Command::FAILURE;
        } catch (Throwable $exception) {
            $this->error(sprintf('%s : %s', $exception->getCode(), $exception->getMessage()));
            Log::error('vendor_detect failed', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 取得待檢測的遊戲商清單。
     *
     * 依 vendor_id + general_agent_id 分組，取該組最新一筆 fail 紀錄（含 member_id）。
     * 僅包含轉帳錢包（is_single_wallet = 0），且建立時間在一週內的紀錄。
     *
     * @return Collection<int, VendorRequestFail>
     */
    private function getPendingFailedVendors(): Collection
    {
        $latestIds = VendorRequestFail::query()
            ->where('status', '<', 2) // 排除已恢復（status=2）
            ->where('created_at', '>=', now()->subWeek())
            ->whereHas('vendor', function (Builder $query): void {
                $query->where('is_single_wallet', 0); // 單錢包無轉點，略過
            })
            ->selectRaw('MAX(id) as id')
            ->groupBy('vendor_id', 'general_agent_id')
            ->pluck('id');

        if ($latestIds->isEmpty()) {
            return collect();
        }

        return VendorRequestFail::query()
            ->whereIn('id', $latestIds)
            ->orderBy('vendor_id')
            ->with('vendor:id,name')
            ->get();
    }

    /**
     * 解析各總代的 vendor_detect 探針 Member，組成 {general_agent_id => Member} 對應表。
     *
     * 沿用舊系統 params(type=vendor_detect)：每筆 info 為 JSON、含 account（探針會員 username）。
     * 由 account → Member → member->agent->general_agent_id 推導出對應總代；
     * 解析失敗（info 非 JSON、缺 account、account 查無 member 或無上層代理、general_agent_id
     * 推導拋錯）安全略過並記 warning，不中斷整個排程。
     * 同一 general_agent_id 出現多筆時以最先解析成功者為準。
     *
     * @return Collection<int, Member> key 為 general_agent_id，value 為探針 Member
     */
    private function resolveGeneralAgentProbeMembers(): Collection
    {
        $params = Param::query()
            ->where('type', 'vendor_detect')
            ->where('status', 1)
            ->get();

        $probeMembers = collect();

        foreach ($params as $param) {
            $info = json_decode((string) $param->info, true);

            if (!is_array($info) || !isset($info['account']) || !is_string($info['account']) || $info['account'] === '') {
                Log::warning('vendor_detect probe param has no valid account', [
                    'param_id' => $param->id,
                ]);

                continue;
            }

            $account = $info['account'];

            $member = Member::query()
                ->with('agent')
                ->where('username', $account)
                ->first();

            if (!$member || !$member->agent) {
                Log::warning('vendor_detect probe member not found', [
                    'param_id' => $param->id,
                    'account' => $account,
                ]);

                continue;
            }

            $generalAgentId = $member->agent->general_agent_id;

            // general_agent_id accessor 在上層 hierarchy 無總代時回傳 null（非拋例外），
            // 單筆探針壞資料安全略過，不可中斷整個方法導致該輪所有 vendor 都不檢測
            if ($generalAgentId === null) {
                Log::warning('vendor_detect probe general agent resolve failed', [
                    'param_id' => $param->id,
                    'account' => $account,
                ]);

                continue;
            }

            $generalAgentId = (int) $generalAgentId;

            // 同一總代已有探針時保留先解析成功者，避免後筆覆蓋
            if ($probeMembers->has($generalAgentId)) {
                continue;
            }

            $probeMembers->put($generalAgentId, $member);
        }

        return $probeMembers;
    }

    /**
     * 透過 Vendor Driver 查詢餘額，判斷三方是否恢復。
     *
     * 僅轉帳錢包（AbstractTransferWallet）支援 getBalance()；
     * 查詢失敗或 driver 非轉帳錢包時回傳 false，不拋出例外。
     */
    private function tryFetchVendorBalance(VendorService $vendorService, int $vendorId, Member $member): bool
    {
        try {
            $driver = $vendorService->getDriver($vendorId, $member);

            if (!$driver instanceof AbstractTransferWallet) {
                Log::info('vendor_detect skipped non-transfer-wallet vendor', [
                    'vendor_id' => $vendorId,
                    'driver' => $driver::class,
                ]);

                return false;
            }

            $driver->getBalance();

            return true;
        } catch (Throwable $e) {
            Log::warning('vendor_detect getBalance failed', [
                'vendor_id' => $vendorId,
                'member_id' => $member->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 查詢總代該遊戲商目前有效的維護紀錄（含後台手動掛的，由呼叫端依 admin 分流）。
     *
     * @return Collection<int, VendorMaintenance>
     */
    private function findActiveMaintenances(int $vendorId, int $generalAgentId): Collection
    {
        return VendorMaintenance::query()
            ->where('vendor_id', $vendorId)
            ->where('start_time', '<=', now())
            ->where('end_time', '>=', now())
            ->where('agent_id', $generalAgentId)
            ->whereNot('exclude_agent_id', $generalAgentId)
            ->where('status', 1)
            ->where('type', 1)  // 只查詢遊戲跟錢包都關閉的維護
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 餘額查詢成功：關閉自動維護、標記 fail 為已恢復。
     *
     * $maintenance 僅會是排程自建（admin=0）的維護；後台手動維護不在此關閉。
     * 僅在 previousStatus=1（先前已發過失敗通知）時回傳恢復通知文字。
     *
     * @return string 通知訊息；無需通知時回傳空字串
     */
    private function handleBalanceSuccess(
        string $agentUsername,
        string $vendorName,
        int $vendorId,
        int $generalAgentId,
        int $previousStatus,
        ?VendorMaintenance $maintenance,
        bool $hasManualMaintenance,
    ): string {
        DB::transaction(function () use ($vendorId, $generalAgentId, $maintenance): void {
            // 關閉先前因 fail 自動開啟的維護
            if ($maintenance !== null) {
                $maintenance->status = 0;
                $maintenance->memo = '自動維護排程取消維護';
                $maintenance->save();
            }

            // 標記為已恢復，後續排程不再檢測
            VendorRequestFail::query()
                ->where('vendor_id', $vendorId)
                ->where('status', '<', 2)
                ->where('general_agent_id', $generalAgentId)
                ->update(['status' => 2]);
        });

        // status=0 代表尚未發過失敗通知，恢復時不需通知
        if ($previousStatus !== 1) {
            return '';
        }

        $maintenanceNotice = match (true) {
            $maintenance !== null => '遊戲維護已關閉',
            $hasManualMaintenance => '後台手動維護仍生效，未自動關閉',
            default => '無自動維護需關閉',
        };

        return sprintf(
            '【%s-%s】已恢復轉點；%s%s%s',
            $agentUsername,
            $vendorName,
            PHP_EOL,
            $maintenanceNotice,
            PHP_EOL,
        );
    }

    /**
     * 餘額查詢失敗：開啟或新增維護、升級 fail 狀態。
     *
     * $maintenance 僅會是排程自建（admin=0）的維護，只延長它；
     * 後台手動維護（$hasManualMaintenance）期間不新建自動維護，避免重複維護與重複告警。
     * 僅在新建 VendorMaintenance 時回傳告警文字；延長既有維護不重複發送 Telegram。
     *
     * @return string 通知訊息；無需通知時回傳空字串
     */
    private function handleBalanceFailure(
        Agent $agent,
        string $vendorName,
        int $vendorId,
        int $generalAgentId,
        ?VendorMaintenance $maintenance,
        bool $hasManualMaintenance,
    ): string {
        $now = Carbon::now();
        $maintenanceEnd = $now->copy()->addHour();

        // 後台手動維護中：轉點已被擋，排程不再插一筆自動維護
        if ($maintenance === null && $hasManualMaintenance) {
            Log::info('vendor_detect skipped creating maintenance due to manual maintenance', [
                'vendor_id' => $vendorId,
                'general_agent_id' => $generalAgentId,
            ]);

            return '';
        }

        $createdNewMaintenance = DB::transaction(function () use ($vendorId, $generalAgentId, $maintenance, $now, $maintenanceEnd): bool {
            if ($maintenance !== null) {
                if (Carbon::parse($maintenance->end_time)->lessThan($maintenanceEnd)) {
                    $maintenance->end_time = $maintenanceEnd->toDateTimeString();
                    $maintenance->memo = '自動維護排程延長維護時間';
                    $maintenance->save();
                }

                return false;
            }

            VendorMaintenance::query()->create([
                'vendor_id' => $vendorId,
                'type' => 1,
                'start_time' => $now->toDateTimeString(),
                'end_time' => $maintenanceEnd->toDateTimeString(),
                'agent_id' => $generalAgentId,
                'status' => 1,
                'admin' => 0,
                'memo' => '自動維護排程建立維護',
            ]);

            VendorRequestFail::query()
                ->where('vendor_id', $vendorId)
                ->where('status', 0)
                ->where('general_agent_id', $generalAgentId)
                ->update(['status' => 1]);

            return true;
        });

        if (!$createdNewMaintenance) {
            return '';
        }

        // 通知文案沿用舊版 RD_studio_01 的 +8/+9 小時區間描述
        $notifyStart = $now->copy()->addHours(8);
        $notifyEnd = $now->copy()->addHours(9);

        return sprintf(
            '【%s-%s】無法取得餘額，暫時關閉轉點；%s已設定遊戲維護 %s 到 %s%s',
            $agent->username,
            $vendorName,
            PHP_EOL,
            $notifyStart->toDateTimeString(),
            $notifyEnd->toDateTimeString(),
            PHP_EOL,
        );
    }
}
