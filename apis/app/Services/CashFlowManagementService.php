<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AdminOperationEnum;
use App\Models\CashFlow;
use Illuminate\Support\Facades\DB;

/**
 * Olympus「金流商管理」的寫入服務：目前**只開放修改 `cash_flows.whitelist`**（回調來源 IP 白名單）。
 *
 * 硬規則：
 * - 🔴 只接受 whitelist 一個欄位，其餘一律丟棄。編輯頁雖然把別的欄位設成唯讀，但請求內容可被
 *   竄改，服務層不能只靠表單沒放這些欄位。放寬開放範圍前先想清楚：`third_party_code` 是
 *   Driver 的解析鍵（{@see PaymentService::getProvider()} 拿它去 config/payment.php
 *   查 class），`type` 決定代收／代付分流，`rate`／`percentage` 會進金額換算——改錯都不會馬上被發現。
 * - 寫入與 {@see AdminOperationService::audit()} 必須在同一個交易內，稽核寫不進去整筆回滾。
 * - 讀取一律 `lockForUpdate()` 重取，改前值以鎖定後的值為準，兩人同時編輯不會記錯改前值。
 * - `admin` 欄位不寫，操作者以操作紀錄為準。
 * - 不清快取：cash_flows 沒有快取層，回調當下是經 `agent_cash_flow->cashFlow` 直接查 DB。
 */
readonly class CashFlowManagementService
{
    public function __construct(private AdminOperationService $adminOperationService) {}

    /**
     * 修改白名單；內容沒有變動時不寫入、不留紀錄。
     *
     * @param  array<string, mixed>  $data  表單資料（只讀 whitelist，其餘忽略）
     * @return array{from: ?string, to: ?string}|null 有變動時回傳改前改後，沒變動回 null
     */
    public function updateWhitelist(CashFlow $cashFlow, array $data, int $userId): ?array
    {
        $whitelist = $this->normalizeWhitelist($data['whitelist'] ?? null);

        return DB::transaction(function () use ($cashFlow, $whitelist, $userId): ?array {
            $locked = CashFlow::whereKey($cashFlow->id)->lockForUpdate()->firstOrFail();
            $from = $locked->whitelist;

            if ($from === $whitelist) {
                return null;
            }

            $locked->whitelist = $whitelist;
            $locked->save();

            $this->adminOperationService->audit(
                operation: AdminOperationEnum::UpdateCashFlowWhitelist,
                userId: $userId,
                params: [
                    'cash_flow_id' => $locked->id,
                    'cash_flow_name' => $locked->name,
                    'changes' => ['whitelist' => ['from' => $from, 'to' => $whitelist]],
                ],
                output: sprintf(
                    '修改金流商 %s (ID: %d) 白名單：%s → %s',
                    $locked->name,
                    $locked->id,
                    $from === null || $from === '' ? '（空）' : $from,
                    $whitelist ?? '（空）',
                ),
            );

            return ['from' => $from, 'to' => $whitelist];
        });
    }

    /**
     * 去掉每一筆的前後空白；留空存 null。
     *
     * ⚠️ 留空的語意由**讀取端**決定，不是這裡：遊戲商回調（{@see \App\Helpers\Common::verifyIPWhitelist()}）
     * 留空＝不檢查，但無驗簽的金流商回調留空＝一律拒絕（fail-closed）。
     * 所以清空某些支付商的白名單等於把該支付商的回調全部擋掉，不是「關掉檢查」。
     *
     * 🔴 **這個欄位是即時生效的防線**：讀取端是 {@see Payment\Traits\CallbackSourceGuardTrait}，
     * 目前 Ap8Pay、EasyPay、MaxPay、TngPin 的代收與代付回調都讀它。清空等於讓那家的回調全部
     * 被拒絕、該渠道的儲值當場中斷，不會有任何錯誤訊息，症狀只有「都不上分」。
     */
    private function normalizeWhitelist(mixed $value): ?string
    {
        $whitelist = trim((string) $value);

        if ($whitelist === '') {
            return null;
        }

        return implode(',', array_map(trim(...), explode(',', $whitelist)));
    }
}
