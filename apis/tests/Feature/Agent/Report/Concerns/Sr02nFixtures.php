<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Report\Concerns;

use App\Enums\Agent\ReservedAgentIdEnum;
use App\Enums\Agent\RoleEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentCashFlow;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\BankAccount;
use App\Models\BankTransactionLog;
use App\Models\CashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OtaOrder;
use App\Models\TransactionLog;
use App\Models\TransactionLogAgent;
use App\Models\WalletTransactionLog;
use App\Models\WalletTransactionLogAgent;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * SR02N 測試共用夾具（target/tickets#4356）。
 *
 * 時間一律以 UTC 寫入；查詢帶 GMT+8 牆鐘（測試固定 frontend timezone 為 Asia/Taipei）。
 * 所有資料列只由本測試建立，靠 Tests\TestCase 的 DatabaseTransactions 回滾。
 */
trait Sr02nFixtures
{
    /** 預設查詢期間（+8 牆鐘）：2026-09-10 整天 ⇒ UTC 09-09 16:00 ~ 09-10 15:59:59 */
    protected const string DAY_START = '2026-09-10 00:00:00';

    protected const string DAY_END = '2026-09-10 23:59:59';

    /** 期間內的 UTC 時間點 */
    protected const string IN_RANGE_UTC = '2026-09-10 02:00:00';

    protected function setUpSr02n(): void
    {
        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        // 選單 code→id 對應表走快取，不清會讀到前一個測試的選單 id
        Cache::flush();
        config()->set('custom.frontend.timezone', 'Asia/Taipei');
    }

    /** 端點掛 `CheckAgentPermission:report_sr02n`，測試需自行建選單並授權 */
    protected function grantSr02nMenu(Agent $agent): void
    {
        $menu = AgentMenu::query()->where('code', 'report_sr02n')->first() ?? AgentMenu::create([
            'code' => 'report_sr02n',
            'name' => 'SR02N 開洗分明細表',
            'parent' => 0,
            'url' => 'report/sr02n',
            'action' => '',
            'sort' => 11,
            'status' => 1,
            'admin' => 0,
        ]);

        AgentPermission::create([
            'agent_id' => $agent->id,
            'agent_menu_id' => $menu->id,
            'action' => '',
            'admin' => 0,
        ]);
    }

    protected function loginAs(Agent $agent): void
    {
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    /** 下級代理（hierarchy_path 接在上級之後） */
    protected function createSubAgent(Agent $parent, int $roleId = 0): Agent
    {
        $agent = Agent::factory()->create([
            'parent' => $parent->id,
            'username' => 'sub' . bin2hex(random_bytes(6)),
            'role_id' => $roleId,
        ]);
        $agent->hierarchy_path = "{$parent->hierarchy_path}/{$agent->id}";
        $agent->hierarchy = "[-1,{$agent->id},{$parent->id},-2]";
        $agent->save();

        return $agent;
    }

    /** 子帳號（host_id＝主帳號；路徑同 dev 實況＝主帳號路徑/自身 id） */
    protected function createSubAccount(Agent $host, int $roleId = 0): Agent
    {
        $agent = Agent::factory()->create([
            'parent' => $host->id,
            'host_id' => $host->id,
            'username' => 'acc' . bin2hex(random_bytes(6)),
            'role_id' => $roleId,
        ]);
        $agent->hierarchy_path = "{$host->hierarchy_path}/{$agent->id}";
        $agent->save();

        return $agent;
    }

    /**
     * 頂層代理（路徑 /1/<id>，與正式資料一致掛在 Admin 之下）。
     *
     * `createAgentAndDomain()` 綁的網域全庫唯一、同一測試只能用一次，故只給登入者用（$withDomain），其餘走 factory。
     */
    protected function createTopAgent(int $roleId = RoleEnum::GENERAL_AGENT->value, bool $withDomain = false): Agent
    {
        $agent = $withDomain
            ? $this->createAgentAndDomain(['role_id' => $roleId])
            : Agent::factory()->create(['username' => 'top' . bin2hex(random_bytes(6)), 'role_id' => $roleId]);
        $agent->hierarchy_path = "/1/{$agent->id}";
        $agent->save();

        return $agent;
    }

    /** Admin 子帳號（host_id＝1，Admin 線） */
    protected function adminSubAccount(): Agent
    {
        $agent = Agent::factory()->create([
            'username' => 'adm' . bin2hex(random_bytes(6)),
            'host_id' => ReservedAgentIdEnum::ADMIN->value,
            'parent' => ReservedAgentIdEnum::ADMIN->value,
            'role_id' => RoleEnum::ADMIN->value,
        ]);
        $agent->hierarchy_path = '/1/' . $agent->id;
        $agent->save();

        return $agent;
    }

    /** System 帳號（id 51）；測試庫已有就沿用 */
    protected function systemAgent(): Agent
    {
        return Agent::query()->find(ReservedAgentIdEnum::SYSTEM->value)
            ?? Agent::factory()->create(['id' => ReservedAgentIdEnum::SYSTEM->value, 'username' => 'sys' . bin2hex(random_bytes(4))]);
    }

    protected function createSr02nMember(Agent $agent): Member
    {
        return $this->createMember($agent, [
            'username' => 'm' . bin2hex(random_bytes(6)),
            'nickname' => 'n' . bin2hex(random_bytes(6)),
        ]);
    }

    /**
     * 主錢包紀錄。未指定 operator 時比照後台開洗分：operator ＝ agent_id 那位代理的帳號（agent_id=0 時 'system'）。
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function mainLog(Member $member, array $attributes = []): int
    {
        $agentId = (int) ($attributes['agent_id'] ?? $member->parent);
        $attributes['operator'] ??= $agentId === 0 ? 'system' : (string) Agent::query()->whereKey($agentId)->value('username');

        return TransactionLog::query()->insertGetId(array_merge([
            'transaction_no' => 'T' . bin2hex(random_bytes(8)),
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'vendor_id' => 0,
            'order_id' => 0,
            'type' => TransactionEnum::DEPOSIT->value,
            'point' => '100.0000',
            'tax' => 0,
            'vendor_point' => 0,
            'rate' => 1,
            'before_balance' => 0,
            'balance' => '100.0000',
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'remark' => '',
            'platform' => 2,
            'admin' => 0,
            'created_at' => self::IN_RANGE_UTC,
            'updated_at' => self::IN_RANGE_UTC,
        ], $attributes));
    }

    /** 客服手動洗分（型別 5、無取款訂單），由會員上層代理操作 */
    protected function csWithdrawal(Member $member, string $point): int
    {
        return $this->mainLog($member, ['type' => TransactionEnum::WITHDRAWAL->value, 'point' => $point]);
    }

    /** 會員拒絕洗分（型別 11）：點數退回，與被拒絕的那筆洗分沒有關聯欄位（target/tickets#4493） */
    protected function refusal(Member $member, string $point): int
    {
        return $this->mainLog($member, ['type' => TransactionEnum::REFUSE_TO_WITHDRAWAL->value, 'point' => $point]);
    }

    /**
     * 遊戲錢包紀錄。未指定 operator 時比照後台開洗分：operator ＝ agent_id（代理 id 字串）。
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function gameLog(Member $member, array $attributes = []): int
    {
        $attributes['operator'] ??= (string) ($attributes['agent_id'] ?? $member->parent);

        return WalletTransactionLog::query()->insertGetId(array_merge([
            'transaction_no' => 'G' . bin2hex(random_bytes(8)),
            'wallet_type_id' => 2,
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'vendor_id' => 0,
            'order_id' => 0,
            'type' => 6,
            'operator_before_balance' => 0,
            'operator_balance' => 0,
            'point' => '50.0000',
            'vendor_point' => 0,
            'tax' => 0,
            'rate' => 1,
            'before_balance' => 0,
            'balance' => '50.0000',
            'vendor_balance' => 0,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'remark' => '',
            'checksum' => '',
            'result' => '',
            'platform' => 2,
            'admin' => 0,
            'created_at' => self::IN_RANGE_UTC,
            'updated_at' => self::IN_RANGE_UTC,
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function agentCreditLog(Agent $from, Agent $to, array $attributes = []): int
    {
        return TransactionLogAgent::query()->insertGetId(array_merge([
            'from_agent_id' => $from->id,
            'to_agent_id' => $to->id,
            'type' => 1,
            'order_id' => 0,
            'amount' => '30.0000',
            'rolling_chip_amount' => 0,
            'from_limit_of_credit' => 0,
            'from_used_credit' => 0,
            'to_limit_of_credit' => '1000.0000',
            'to_used_credit' => '100.0000',
            'operator' => $from->username,
            'platform' => 2,
            'remark' => '',
            'status' => 1,
            'created_at' => self::IN_RANGE_UTC,
            'updated_at' => self::IN_RANGE_UTC,
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function agentWalletLog(Agent $operator, Agent $target, array $attributes = []): int
    {
        return WalletTransactionLogAgent::query()->insertGetId(array_merge([
            'wallet_type_id' => 1,
            'agent_id' => $target->id,
            'type' => 1,
            'amount' => '20.0000',
            'before_balance' => 0,
            'balance' => '20.0000',
            'operator' => $operator->id,
            'operator_before_balance' => 0,
            'operator_balance' => 0,
            'remark' => '',
            'status' => 1,
            'created_at' => self::IN_RANGE_UTC,
            'updated_at' => self::IN_RANGE_UTC,
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function withdrawalOrder(int $memberId, array $attributes = []): WithdrawalOrder
    {
        return WithdrawalOrder::factory()->create(array_merge([
            'member_id' => $memberId,
            'amount' => '100.0000',
            'point' => '100.0000',
            'rate' => 1,
            'tax' => 0,
        ], $attributes));
    }

    protected function payoutRecord(WithdrawalOrder $order, int $cashFlowId, int $agentCashFlowId): int
    {
        return WithdrawalPayoutRecord::query()->insertGetId([
            'cash_flow_id' => $cashFlowId,
            'agent_cash_flow_id' => $agentCashFlowId,
            'withdrawal_order_id' => $order->id,
            'transaction_no' => 'P' . bin2hex(random_bytes(6)),
            'third_party_transaction_no' => 'TP' . bin2hex(random_bytes(6)),
            'result_amount' => $order->point,
            'result_code' => 'ok',
            'is_success_payout' => WithdrawalPayoutStatusEnum::SUCCESS->value,
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
    }

    /** 收付款帳戶異動（source 3＝自助上分 orders.id、4＝自助洗分 withdrawal_orders.id），回帳戶暱稱 */
    protected function bankLog(int $orderId, int $source, int $memberId): string
    {
        $account = BankAccount::factory()->create(['order_type_id' => 0, 'account_nickname' => 'ACC' . bin2hex(random_bytes(4))]);

        BankTransactionLog::query()->insertGetId([
            'order_id' => $orderId,
            'account_id' => $account->id,
            'source' => $source,
            'member_id' => $memberId,
            'point' => '1.0000',
            'before_amount' => 0,
            'remark' => '',
            'operator' => 'op',
        ]);

        return (string) $account->account_nickname;
    }

    protected function otaOrder(Member $member, int $agentCashFlowId): int
    {
        return OtaOrder::query()->insertGetId([
            'reference_number' => 'R' . bin2hex(random_bytes(8)),
            'member_id' => $member->id,
            'token' => '0',
            'agent_cash_flow_id' => $agentCashFlowId,
            'amount' => '10.0000',
            'status' => 2,
            'info' => '',
        ]);
    }

    /** 支付商（id 取高位避免撞到測試庫既有主檔） */
    protected function cashFlow(int $type = 1, string $name = 'CF', ?int $id = null): CashFlow
    {
        return CashFlow::create([
            'id' => $id ?? random_int(800000, 899999),
            'name' => $name,
            'type' => $type,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);
    }

    protected function merchant(CashFlow $cashFlow, int $agentGroupId = 1, string $name = 'MERCHANT'): AgentCashFlow
    {
        return AgentCashFlow::create([
            'agent_group_id' => $agentGroupId,
            'cash_flow_info_id' => 0,
            'cash_flow_id' => $cashFlow->id,
            'name' => $name,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function depositOrder(Member $member, AgentCashFlow $merchant, int $orderTypeId, array $attributes = []): Order
    {
        return Order::factory()->create(array_merge([
            'member_id' => $member->id,
            'agent_cash_flow_id' => $merchant->id,
            'cash_flow_id' => $merchant->cash_flow_id,
            'order_type_id' => $orderTypeId,
            'third_party_order_no' => 'TPO' . bin2hex(random_bytes(6)),
            'admin' => 0,
            'created_at' => self::IN_RANGE_UTC,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    protected function sr02nParams(array $override = []): array
    {
        return array_merge([
            'start_date' => self::DAY_START,
            'end_date' => self::DAY_END,
            'source' => 0,
            'pairs' => [],
            'page' => 1,
            'per_page' => 100,
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<int, array<string, mixed>>
     */
    protected function listItems(array $override = []): array
    {
        $response = $this->requestApi('agent/api/report/sr02n/list', $this->sr02nParams($override));
        $response->assertOk()->assertJsonPath('code', 200);

        return $response->json('data.items');
    }

    /**
     * 列表每列的 [手續費, 實際金額]
     *
     * @param  array<string, mixed>  $override
     * @return array<string, array{0: string|null, 1: string|null}> "segment:id" ⇒ [tax, actual_point]
     */
    protected function moneyColumns(array $override = []): array
    {
        $money = [];
        foreach ($this->listItems($override) as $item) {
            $money[$item['segment'] . ':' . $item['id']] = [$item['tax'], $item['actual_point']];
        }

        return $money;
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<int, string> "segment:id"
     */
    protected function listKeys(array $override = []): array
    {
        return array_map(
            static fn (array $item): string => $item['segment'] . ':' . $item['id'],
            $this->listItems($override),
        );
    }
}
