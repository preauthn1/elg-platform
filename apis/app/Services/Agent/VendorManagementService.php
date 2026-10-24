<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Agent\RoleEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Vendor;
use App\Services\Vendor\VendorDriverCapabilityService;
use Illuminate\Support\Facades\DB;

/**
 * 廠商管理 target/tickets#4400
 *
 * 列表在既有遊戲商欄位之外，補上只有 apis Driver 才知道的資訊：錢包模式、接入通道、
 * 有無 gamelist API、回調路徑。後三項由 VendorDriverCapabilityService 從 Driver 契約推導。
 *
 * 回調路徑規則：
 * - 只有無縫錢包（vendors.is_single_wallet = 1）且 config 有掛 Driver 才適用；轉帳錢包一律不適用、不可編輯。
 * - vendors.callback_path 有值用 DB，NULL 退回 Driver 宣告的預設後綴（HasCallbackPath）。
 * - 完整網址以 config('custom.api_external_url') 為網域（與金流回調同源）；不用 app.url，
 *   那在 k8s 是叢集內部位址，三方打不到。
 * - 只有帳號角色為 admin 的使用者看得到與改得了回調路徑（見 canManageCallback）。
 *
 * @knowledge rules/vendor-and-channel-management
 */
class VendorManagementService
{
    public function __construct(
        private readonly VendorDriverCapabilityService $capabilityService,
    ) {}

    /**
     * 回調路徑只給帳號角色為 admin（agents.role_id = RoleEnum::ADMIN）的使用者看與改（target/tickets#4400 上線前追加）。
     *
     * 刻意不用 Agent::isPlatformAdmin()：那是 id／host_id 判定（admin 本人及其子帳號），
     * 範圍比「角色是 admin」寬。
     */
    public function canManageCallback(Agent $agent): bool
    {
        return (int) $agent->role_id === RoleEnum::ADMIN->value;
    }

    /**
     * 廠商管理列表：全部狀態的遊戲商，依更新時間新到舊（與 API_Agent getVendorList 同序）
     *
     * $includeCallback 為 false 時，每列不放 callback_applicable／default_callback_path／callback_path／callback_url，
     * 頂層也不放 callback_base_url（整個 key 不存在，不是 null）。
     *
     * @return array{items: list<array<string, mixed>>, channels: list<string>, callback_visible: bool, callback_base_url?: string}
     */
    public function list(bool $includeCallback): array
    {
        $baseUrl = $this->callbackBaseUrl();

        $items = Vendor::query()
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Vendor $vendor): array => $this->toRow($vendor, $baseUrl, $includeCallback))
            ->values()
            ->all();

        $result = [
            'items' => $items,
            'channels' => $this->capabilityService->availableChannels(),
            'callback_visible' => $includeCallback,
        ];

        if ($includeCallback) {
            $result['callback_base_url'] = $baseUrl;
        }

        return $result;
    }

    /**
     * 更新回調路徑後綴；傳 null 或空白字串代表清除覆寫、退回 Driver 預設
     *
     * @throws ApiException 遊戲商不存在，或該遊戲商不適用回調路徑
     */
    public function updateCallbackPath(int $vendorId, ?string $path, int $adminId): void
    {
        $path = $path === null ? null : trim($path);

        DB::transaction(function () use ($vendorId, $path, $adminId): void {
            // 讀出後改寫，鎖主鍵列避免與其他後台編輯互相覆蓋
            $vendor = Vendor::query()->lockForUpdate()->find($vendorId);

            if (!$vendor) {
                throw new ApiException(__('error_message.1426'));
            }

            if (!$this->isCallbackApplicable($vendor)) {
                throw new ApiException('轉帳錢包或未掛 Driver 的遊戲商不適用回調路徑');
            }

            $vendor->callback_path = $path === '' ? null : $path;
            $vendor->admin = $adminId;
            $vendor->save();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Vendor $vendor, string $baseUrl, bool $includeCallback): array
    {
        $vendorId = (int) $vendor->id;
        $isSingleWallet = (int) $vendor->is_single_wallet === 1;

        $row = [
            'id' => $vendorId,
            'name' => $vendor->name,
            'rate' => $vendor->rate,
            'percentage' => $vendor->percentage,
            'image' => $vendor->image,
            'app_icon' => $vendor->app_icon,
            'sort' => $vendor->sort,
            'status' => $vendor->status,
            'remark' => $vendor->remark,
            'valid_bet_rate' => $vendor->valid_bet_rate,
            'is_single_wallet' => $vendor->is_single_wallet,
            'wallet_mode' => $isSingleWallet ? 'single' : 'transfer',
            'channel' => $this->capabilityService->channel($vendorId),
            'has_game_list' => $this->capabilityService->hasGameList($vendorId),
        ];

        if (!$includeCallback) {
            return $row;
        }

        $callbackApplicable = $this->isCallbackApplicable($vendor);

        $defaultCallbackPath = $callbackApplicable
            ? $this->capabilityService->defaultCallbackPath($vendorId)
            : null;
        $callbackPath = $callbackApplicable
            ? ($vendor->callback_path ?? $defaultCallbackPath)
            : null;

        return $row + [
            'callback_applicable' => $callbackApplicable,
            'default_callback_path' => $defaultCallbackPath,
            'callback_path' => $callbackPath,
            'callback_url' => $callbackPath !== null ? $baseUrl . $callbackPath : null,
        ];
    }

    /**
     * 是否適用回調路徑：無縫錢包（依 vendors.is_single_wallet，不看 Driver 繼承關係）且 config 有掛 Driver
     */
    private function isCallbackApplicable(Vendor $vendor): bool
    {
        return (int) $vendor->is_single_wallet === 1
            && $this->capabilityService->driverClass((int) $vendor->id) !== null;
    }

    /**
     * 回調網址的網域部分（去尾斜線），後綴一律以 `/` 開頭直接接上
     */
    private function callbackBaseUrl(): string
    {
        return rtrim((string) config('custom.api_external_url'), '/');
    }
}
