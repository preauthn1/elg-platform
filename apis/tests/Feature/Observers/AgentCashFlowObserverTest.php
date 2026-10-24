<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Enums\CashFlowTypeEnum;
use App\Exceptions\ApiException;
use App\Models\AcfCascadeDeactivateLog;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\Order;
use App\Models\OrderType;
use App\Models\WithdrawalPayoutRecord;
use Tests\TestCase;

/**
 * php artisan test --compact tests/Feature/Observers/AgentCashFlowObserverTest.php
 *
 * ACF 下架連動旗下 ACFOT 軟下架，並寫審計 log 供顯式還原。
 *
 * 設計要點（刻意不對稱）：
 * - 下架連動（1→0）：把旗下 status=1 的 ACFOT 一併關掉，避免留下「ACF 已停用但關聯仍 active」的孤兒。
 * - 重啟不連動（0→1）：不自動把 ACFOT 開回，否則會打開營運刻意個別停用的渠道；
 *   還原改為依審計 log 的顯式操作。
 */
class AgentCashFlowObserverTest extends TestCase
{
    private const int CURRENCY_MYR = 1;

    private const int AGENT_GROUP = 688;

    public function test_deactivating_acf_cascades_to_active_acfot_with_audit_log(): void
    {
        [$acf, $acfots] = $this->makeAcfWithAcfots(acfStatus: 1, acfotStatuses: [1, 1]);

        $acf->update(['status' => 0]);

        foreach ($acfots as $acfot) {
            $this->assertSame(0, (int) $acfot->refresh()->status, 'ACF 下架應連動關掉旗下 active ACFOT');
        }

        $logs = AcfCascadeDeactivateLog::query()
            ->where('agent_cash_flow_id', $acf->id)
            ->where('action', AcfCascadeDeactivateLog::ACTION_CASCADE_DEACTIVATE)
            ->get();

        $this->assertCount(2, $logs, '每筆被連動的 ACFOT 應各寫一筆審計');
        $this->assertEqualsCanonicalizing(
            $acfots->pluck('id')->all(),
            $logs->pluck('agent_cash_flow_order_type_id')->map(fn ($id): int => (int) $id)->all(),
        );
        $this->assertSame([1, 1], $logs->pluck('from_status')->map(fn ($s): int => (int) $s)->all(), '應記錄處理前 status 供還原');
        $this->assertSame(
            [AcfCascadeDeactivateLog::SOURCE_OBSERVER, AcfCascadeDeactivateLog::SOURCE_OBSERVER],
            $logs->pluck('source')->all(),
        );
    }

    public function test_already_inactive_acfot_is_not_logged(): void
    {
        [$acf, $acfots] = $this->makeAcfWithAcfots(acfStatus: 1, acfotStatuses: [1, 0]);

        $acf->update(['status' => 0]);

        $logs = AcfCascadeDeactivateLog::query()
            ->where('agent_cash_flow_id', $acf->id)
            ->where('action', AcfCascadeDeactivateLog::ACTION_CASCADE_DEACTIVATE)
            ->get();

        $this->assertCount(1, $logs, '本來就停用的 ACFOT 不該寫 log（否則還原時會誤開營運自行停用的渠道）');
        $this->assertSame((int) $acfots[0]->id, (int) $logs->first()->agent_cash_flow_order_type_id);
    }

    public function test_reactivating_acf_does_not_reopen_acfot(): void
    {
        [$acf, $acfots] = $this->makeAcfWithAcfots(acfStatus: 0, acfotStatuses: [0, 0]);

        $acf->update(['status' => 1]);

        foreach ($acfots as $acfot) {
            $this->assertSame(0, (int) $acfot->refresh()->status, 'ACF 重啟不得自動開回 ACFOT（還原須顯式操作）');
        }

        $this->assertSame(
            0,
            AcfCascadeDeactivateLog::query()->where('agent_cash_flow_id', $acf->id)->count(),
            '重啟不應產生任何審計列',
        );
    }

    public function test_updating_other_columns_does_not_cascade(): void
    {
        [$acf, $acfots] = $this->makeAcfWithAcfots(acfStatus: 1, acfotStatuses: [1]);

        $acf->update(['name' => '(MYR) 改個名字而已']);

        $this->assertSame(1, (int) $acfots[0]->refresh()->status, '非 status 變更不得觸發連動');
        $this->assertSame(0, AcfCascadeDeactivateLog::query()->where('agent_cash_flow_id', $acf->id)->count());
    }

    public function test_deleting_acf_with_referencing_orders_is_blocked(): void
    {
        [$acf, $acfots] = $this->makeAcfWithAcfots(acfStatus: 1, acfotStatuses: [1]);

        $this->makeOrderReferencing($acf, (int) $acfots[0]->order_type_id);

        try {
            $acf->delete();
            $this->fail('有訂單引用的 ACF 不得被刪除（歷史訂單 agent_cash_flow_id 會斷裂）');
        } catch (ApiException $e) {
            $this->assertStringContainsString('訂單', $e->getMessage());
        }

        $this->assertNotNull(
            AgentCashFlow::query()->find($acf->id),
            '刪除應被擋下，ACF 必須仍存在',
        );
    }

    public function test_deleting_acf_without_orders_is_allowed(): void
    {
        [$acf] = $this->makeAcfWithAcfots(acfStatus: 1, acfotStatuses: [1]);

        $acf->delete();

        $this->assertNull(AgentCashFlow::query()->find($acf->id), '無訂單引用時允許刪除');
    }

    public function test_soft_deleting_acf_cascades_soft_delete_to_all_acfots(): void
    {
        [$acf, $acfots] = $this->makeAcfWithAcfots(acfStatus: 1, acfotStatuses: [1, 0]);

        $acf->delete();

        // ACF：軟刪（列保留可回滾）＋ status 歸 0（舊系統只認 status、不認識 deleted_at 的保險）
        $trashedAcf = AgentCashFlow::withTrashed()->find($acf->id);
        $this->assertNotNull($trashedAcf->deleted_at, 'ACF 應為軟刪除（deleted_at 有值）');
        $this->assertSame(0, (int) $trashedAcf->status, '軟刪 ACF 的 status 應歸 0，讓不認識 deleted_at 的舊系統也視為停用');
        $this->assertNull(AgentCashFlow::query()->find($acf->id), '軟刪列不得出現在預設查詢');

        // ACFOT：全數連動軟刪（含本來就停用的），status 原樣保留＝還原後即回到刪除前狀態
        foreach ([0, 1] as $i) {
            $trashed = AgentCashFlowOrderType::withTrashed()->find($acfots[$i]->id);
            $this->assertNotNull($trashed->deleted_at, 'ACFOT 應被連動軟刪');
            $this->assertSame([1, 0][$i], (int) $trashed->status, 'ACFOT status 不得被連動軟刪改動');
        }

        // 審計：每筆 ACFOT 一列 ＋ ACF 自身一列（agent_cash_flow_order_type_id=0）
        $logs = AcfCascadeDeactivateLog::query()
            ->where('agent_cash_flow_id', $acf->id)
            ->where('action', AcfCascadeDeactivateLog::ACTION_SOFT_DELETE)
            ->get();
        $this->assertCount(3, $logs, '應寫 2 筆 ACFOT ＋ 1 筆 ACF 自身審計');
        $this->assertEqualsCanonicalizing(
            [0, (int) $acfots[0]->id, (int) $acfots[1]->id],
            $logs->pluck('agent_cash_flow_order_type_id')->map(fn ($id): int => (int) $id)->all(),
        );
    }

    public function test_restoring_acf_revives_only_cascade_deleted_acfots(): void
    {
        [$acf, $acfots] = $this->makeAcfWithAcfots(acfStatus: 1, acfotStatuses: [1, 0, 1]);

        // 第三筆在 ACF 刪除前已被獨立軟刪＝不屬於本次連動，還原時不得被誤救
        $acfots[2]->delete();

        $acf->delete();
        AgentCashFlow::withTrashed()->find($acf->id)->restore();

        // ACF 復活但 status 維持 0（人工確認後再顯式啟用）
        $revived = AgentCashFlow::query()->find($acf->id);
        $this->assertNotNull($revived, '還原後 ACF 應回到預設查詢');
        $this->assertSame(0, (int) $revived->status, '還原不得自動啟用 ACF');

        // 連動刪的兩筆復活、status 原樣保留
        foreach ([0, 1] as $i) {
            $back = AgentCashFlowOrderType::query()->find($acfots[$i]->id);
            $this->assertNotNull($back, '連動軟刪的 ACFOT 應隨還原復活');
            $this->assertSame([1, 0][$i], (int) $back->status, '還原後 status 應為刪除前原值');
        }

        $this->assertNull(
            AgentCashFlowOrderType::query()->find($acfots[2]->id),
            '獨立軟刪（非本次連動）的 ACFOT 不得被誤救',
        );

        // 閉環：soft_delete log 全數回填 restored_by_log_id，重複還原不再命中
        $pending = AcfCascadeDeactivateLog::query()
            ->where('agent_cash_flow_id', $acf->id)
            ->where('action', AcfCascadeDeactivateLog::ACTION_SOFT_DELETE)
            ->whereNull('restored_by_log_id')
            ->count();
        $this->assertSame(0, $pending, '還原後不得殘留待還原的 soft_delete 紀錄');
    }

    public function test_deleting_acf_with_payout_records_is_blocked(): void
    {
        [$acf] = $this->makeAcfWithAcfots(acfStatus: 1, acfotStatuses: [1]);

        WithdrawalPayoutRecord::create([
            'cash_flow_id' => $acf->cash_flow_id,
            'agent_cash_flow_id' => $acf->id,
            'withdrawal_order_id' => 990101,
            'transaction_no' => 'CASCADE' . uniqid(),
            'third_party_transaction_no' => 'TP' . uniqid(),
            'result_amount' => 100,
            'result_code' => 'SUCCESS',
            'is_success_payout' => 1,
            'transaction_time' => '',
            'sign' => '',
            'payout_order_request_body' => '',
            'payout_order_response' => '',
            'callback_request_body' => '',
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
            'admin' => 0,
        ]);

        try {
            $acf->delete();
            $this->fail('有代付紀錄引用的 ACF 不得被刪除');
        } catch (ApiException $e) {
            $this->assertStringContainsString('withdrawal_payout_records', $e->getMessage());
        }

        $this->assertNotNull(AgentCashFlow::query()->find($acf->id), '刪除應被擋下，ACF 必須仍可見');
    }

    public function test_creating_acf_inherits_cash_flow_type_from_cash_flow(): void
    {
        $cashFlow = $this->makeCashFlow(CashFlowTypeEnum::WITHDRAW->value);

        $acf = $this->makeAcf($cashFlow->id);

        $this->assertSame(
            CashFlowTypeEnum::WITHDRAW->value,
            (int) $acf->refresh()->cash_flow_type,
            '建立 ACF 未帶 cash_flow_type 時應自 CF 繼承 type',
        );
    }

    public function test_creating_acf_overwrites_provided_cash_flow_type(): void
    {
        $cashFlow = $this->makeCashFlow(CashFlowTypeEnum::WITHDRAW->value);

        $acf = $this->makeAcf($cashFlow->id, ['cash_flow_type' => CashFlowTypeEnum::DEPOSIT->value]);

        $this->assertSame(
            CashFlowTypeEnum::WITHDRAW->value,
            (int) $acf->refresh()->cash_flow_type,
            'cash_flow_type 是衍生欄，建立時一律以 CF 為準、不接受外部指定值',
        );
    }

    public function test_updating_cash_flow_id_rederives_cash_flow_type(): void
    {
        $deposit = $this->makeCashFlow(CashFlowTypeEnum::DEPOSIT->value);
        $withdraw = $this->makeCashFlow(CashFlowTypeEnum::WITHDRAW->value);
        $acf = $this->makeAcf($deposit->id);

        $acf->update(['cash_flow_id' => $withdraw->id]);

        $this->assertSame(
            CashFlowTypeEnum::WITHDRAW->value,
            (int) $acf->refresh()->cash_flow_type,
            'cash_flow_id 被改指向他 CF（provider 版本搬遷型操作）時應重新繼承 type',
        );
    }

    public function test_creating_acf_with_dangling_cash_flow_id_leaves_type_null(): void
    {
        $acf = $this->makeAcf(99999999);

        $this->assertNull(
            $acf->refresh()->cash_flow_type,
            '懸空 cash_flow_id 找不到 CF 時不得拋錯，type 留 NULL 由排程收斂',
        );
    }

    private function makeCashFlow(int $type): CashFlow
    {
        return CashFlow::create([
            'name' => 'Test Type ' . uniqid(),
            'type' => $type,
            'third_party_code' => 'D_TTPAY',
            'status' => 1,
            'admin' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAcf(int $cashFlowId, array $overrides = []): AgentCashFlow
    {
        return AgentCashFlow::create([
            'cash_flow_id' => $cashFlowId,
            'agent_group_id' => self::AGENT_GROUP,
            'currency_id' => self::CURRENCY_MYR,
            'cash_flow_info_id' => 0,
            'name' => '(MYR) Inherit Test',
            'rate' => 1,
            'info' => [],
            'status' => 1,
            'admin' => 0,
            ...$overrides,
        ]);
    }

    private function makeOrderReferencing(AgentCashFlow $acf, int $orderTypeId): Order
    {
        return Order::create([
            'order_no' => 'CASCADE' . uniqid(),
            'third_party_order_no' => 'TP' . uniqid(),
            'agent_cash_flow_id' => $acf->id,
            'cash_flow_info_id' => 0,
            'cash_flow_id' => $acf->cash_flow_id,
            'order_type_id' => $orderTypeId,
            'currency_id' => self::CURRENCY_MYR,
            'member_id' => 990002,
            'amount' => 100,
            'point' => 100,
            'balance' => 100,
            'remark' => '',
            'admin' => 0,
        ]);
    }

    /**
     * @param  array<int, int>  $acfotStatuses
     * @return array{0: AgentCashFlow, 1: \Illuminate\Support\Collection<int, AgentCashFlowOrderType>}
     */
    private function makeAcfWithAcfots(int $acfStatus, array $acfotStatuses): array
    {
        $cashFlow = CashFlow::create([
            'name' => 'Test Cascade ' . uniqid(),
            'type' => 1,
            'third_party_code' => 'D_TTPAY',
            'status' => 1,
            'admin' => 0,
        ]);

        $acf = AgentCashFlow::create([
            'cash_flow_id' => $cashFlow->id,
            'agent_group_id' => self::AGENT_GROUP,
            'currency_id' => self::CURRENCY_MYR,
            'cash_flow_info_id' => 0,
            'name' => '(MYR) Cascade Test',
            'rate' => 1,
            'info' => ['API_URL' => 'https://merchant.example'],
            'status' => $acfStatus,
            'admin' => 0,
        ]);

        $acfots = collect($acfotStatuses)->map(function (int $status, int $i) use ($cashFlow, $acf): AgentCashFlowOrderType {
            $orderType = OrderType::create([
                'cash_flow_id' => $cashFlow->id,
                'currency_id' => self::CURRENCY_MYR,
                'name' => 'Cascade OT ' . $i,
                'type' => 1,
                'status' => 1,
                'parent' => 0,
                'key' => 'CASCADE_' . $i,
                'choose_bank' => 0,
                'info' => '{}',
                'admin' => 0,
            ]);

            return AgentCashFlowOrderType::create([
                'agent_cash_flow_id' => $acf->id,
                'order_type_id' => $orderType->id,
                'cash_flow_id' => $cashFlow->id,
                'min_amount' => 0,
                'max_amount' => 0,
                'available_amount' => '',
                'settings' => '{}',
                'sorts' => 0,
                'status' => $status,
            ]);
        });

        return [$acf, $acfots];
    }
}
