<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Report;

use App\Enums\Report\Sr02nReportTypeSet;
use App\Enums\Report\Sr02nSegmentEnum;
use App\Enums\Report\Sr02nSourceKindEnum;
use App\Enums\Report\Sr02nTypeCodeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionLogAgentStatusEnum;
use App\Enums\Transaction\TransactionLogAgentTypeEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * SR02N 列表單列（target/tickets#4356）：把 UNION 原值映射成 SR02 的顯示值。
 *
 * 映射全部在 PHP 做（SR02 在 SQL CASE 裡拼語系字串，本專案禁止）；型別值域與 SR02 相同，
 * 前端沿用 `transaction_type.<type>` 翻譯。`id` 與 `to_agent_username` 保留原值供明細彈窗使用。
 *
 * @knowledge rules/report-sr02n
 *
 * @property object $resource Sr02nReportService::list() 的列（含 attachMoney 補上的 tax／actual_point）
 */
class Sr02nRowResource extends JsonResource
{
    private const string NO_ACCOUNT_NICKNAME = '-';

    /** 代理列狀態代碼（前端翻譯） */
    public const string STATUS_SUCCESS = 'success';

    public const string STATUS_FAIL = 'fail';

    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $segment = Sr02nSegmentEnum::from((string) $row->segment);
        [$sourceKind, $sourceName] = $this->source($segment, $row);

        return [
            /** @var int 原紀錄 id（依 platform／wallet_type 對應原表） */
            'id' => (int) $row->id,
            /** @var string 段別 main／game／agent_credit／agent_wallet */
            'segment' => $segment->value,
            /** @var int 1:會員 2:代理 */
            'platform' => $segment->platform(),
            /** @var int 1:主錢包／額度 2:遊戲錢包／佔成錢包 */
            'wallet_type' => $segment->walletType(),
            /** @var string 時間（前端時區牆鐘） */
            'created_at' => Carbon::parse((string) $row->created_at, 'UTC')->toFrontendTime()->toDateTimeString(),
            'transaction_no' => (string) ($row->transaction_no ?? ''),
            'third_party_order_no' => (string) ($row->third_party_order_no ?? ''),
            'agent_username' => (string) ($row->agent_username ?? ''),
            'to_agent_username' => (string) ($row->to_agent_username ?? ''),
            'member_username' => (string) ($row->member_username ?? ''),
            'member_first_name' => (string) ($row->member_first_name ?? ''),
            'member_nickname' => (string) ($row->member_nickname ?? ''),
            /** @var int|string|null 操作（數值沿用 transaction_logs.type 值域，或 Sr02nTypeCodeEnum 字串） */
            'type' => $this->type($segment, $row),
            /** @var string 變更點數 */
            'point' => $this->money($row->point ?? null),
            /** @var string 變更後點數 */
            'balance' => $this->money($row->balance ?? null),
            /** @var string|null 手續費（平台點數；洗分列有值、無提款單為 0.0000，PM 2026-09-30 定案 B；非洗分列與被拒絕的洗分列為 null，target/tickets#4493） */
            'tax' => $row->tax ?? null,
            /** @var string|null 實際金額（變更點數 − 手續費；非洗分列與被拒絕的洗分列為 null） */
            'actual_point' => $row->actual_point ?? null,
            /** @var string 來源種類 cash_flow／customer_service／system／ota */
            'source_kind' => $sourceKind->value,
            /** @var string|null 來源為 cash_flow 時的支付商名稱 */
            'source_name' => $sourceName,
            'operator_username' => (string) ($row->operator_username ?? ''),
            /** @var string 會員列：收付款帳戶暱稱或「-」；代理列：success／fail */
            'status' => $this->status($segment, $row),
            'remark' => (string) ($row->remark ?? ''),
        ];
    }

    private function type(Sr02nSegmentEnum $segment, object $row): int|string|null
    {
        $rawType = (int) $row->raw_type;

        return match ($segment) {
            Sr02nSegmentEnum::MAIN => $this->mainType($rawType, $row),
            Sr02nSegmentEnum::GAME => $rawType === WalletTransactionEnum::GAME_WALLET_ORDER_DEPOSIT->value
                ? Sr02nTypeCodeEnum::THIRD_PARTY_DEPOSIT->value
                : (Sr02nReportTypeSet::GAME_TYPE_DISPLAY[$rawType] ?? $rawType),
            // SR02 CASE 無 ELSE：開分／洗分以外回 null
            Sr02nSegmentEnum::AGENT_CREDIT => match ($rawType) {
                TransactionLogAgentTypeEnum::DEPOSIT->value => Sr02nTypeCodeEnum::TRANSFER_TO_AGENT->value,
                TransactionLogAgentTypeEnum::WITHDRAWAL->value => Sr02nTypeCodeEnum::DEDUCT_FROM_AGENT->value,
                default => null,
            },
            Sr02nSegmentEnum::AGENT_WALLET => Sr02nReportTypeSet::AGENT_WALLET_TYPE_DISPLAY[$rawType] ?? $rawType,
        };
    }

    /** 主錢包：3→三方充值；5→無提款單維持 5、有提款單但無成功代付＝客服提現、有＝三方代付 */
    private function mainType(int $rawType, object $row): int|string
    {
        if ($rawType === TransactionEnum::ORDER_DEPOSIT->value) {
            return Sr02nTypeCodeEnum::THIRD_PARTY_DEPOSIT->value;
        }

        if ($rawType !== TransactionEnum::WITHDRAWAL->value || $row->withdrawal_order_id === null) {
            return $rawType;
        }

        return $row->payout_cash_flow_id === null
            ? Sr02nTypeCodeEnum::CS_PAYOUT->value
            : Sr02nTypeCodeEnum::THIRD_PARTY_PAYOUT->value;
    }

    /**
     * @return array{0: Sr02nSourceKindEnum, 1: string|null}
     */
    private function source(Sr02nSegmentEnum $segment, object $row): array
    {
        $rawType = (int) $row->raw_type;
        $customerService = [Sr02nSourceKindEnum::CUSTOMER_SERVICE, null];

        if ($segment === Sr02nSegmentEnum::GAME) {
            return $rawType === WalletTransactionEnum::GAME_WALLET_ORDER_DEPOSIT->value
                ? $this->cashFlowSource($row->deposit_cash_flow_name)
                : $customerService;
        }

        if ($segment !== Sr02nSegmentEnum::MAIN) {
            return $customerService;
        }

        return match (true) {
            $rawType === TransactionEnum::ORDER_DEPOSIT->value => $this->cashFlowSource($row->deposit_cash_flow_name),
            $rawType === TransactionEnum::WITHDRAWAL->value => $row->payout_cash_flow_name === null
                ? $customerService
                : $this->cashFlowSource($row->payout_cash_flow_name),
            in_array($rawType, Sr02nReportTypeSet::MAIN_SYSTEM_SOURCE, true) => [Sr02nSourceKindEnum::SYSTEM, null],
            $rawType === TransactionEnum::OTA_DEDUCTION->value => [Sr02nSourceKindEnum::OTA, null],
            default => $customerService,
        };
    }

    /**
     * @return array{0: Sr02nSourceKindEnum, 1: string|null}
     */
    private function cashFlowSource(?string $name): array
    {
        return [Sr02nSourceKindEnum::CASH_FLOW, $name];
    }

    private function status(Sr02nSegmentEnum $segment, object $row): string
    {
        if ($segment->isMemberSegment()) {
            return (string) ($row->account_nickname ?? self::NO_ACCOUNT_NICKNAME);
        }

        return (int) $row->raw_status === TransactionLogAgentStatusEnum::SUCCESS->value ? self::STATUS_SUCCESS : self::STATUS_FAIL;
    }

    private function money(mixed $value): string
    {
        return Common::numAdd('0', (string) ($value ?? '0'), Sr02nReportTypeSet::MONEY_SCALE);
    }
}
