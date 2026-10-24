<?php

declare(strict_types=1);

namespace App\Services\Agent\Report;

use App\Data\Report\Sr02nFilter;
use App\Data\Report\Sr02nScope;
use App\Enums\Report\Sr02nReportTypeSet;
use App\Enums\Report\Sr02nSegmentEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Services\Agent\Report\Sr02n\Segments\AbstractSr02nSegment;
use App\Services\Agent\Report\Sr02n\Segments\AgentCreditSegment;
use App\Services\Agent\Report\Sr02n\Segments\AgentWalletSegment;
use App\Services\Agent\Report\Sr02n\Segments\GameWalletSegment;
use App\Services\Agent\Report\Sr02n\Segments\MainWalletSegment;
use App\Services\Agent\Report\Sr02n\Segments\Sr02nSegmentInterface;
use App\Services\Agent\Report\Sr02n\Sr02nScopeResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * SR02N 開洗分明細表（target/tickets#4356；由 API_Agent SR02 `transactionList`／`transactionTotal` 以 Eloquent 重寫）。
 *
 * 四段（主錢包 → 遊戲錢包 → 代理額度 → 代理佔成錢包）一律 UNION ALL 後外層分頁，
 * 舊 SR02 的 filter／deposit_to 分支拿掉，改由「篩選」配對過濾（{@see Sr02n\Sr02nPairCondition}）。
 * 資料範圍、成功狀態、型別清單等隱藏規則逐條沿用 SR02，驗收基準：全不勾＋來源不限 ＝ SR02 筆數與金額。
 *
 * 手續費與實際金額（PM 2026-09-30 定案 B：洗分列全部計入，無提款單者手續費 0；target/tickets#4493 再排除被拒絕的洗分；
 * 實際金額合計 ≡ 總洗分 − 被拒絕列變更點數 − 手續費合計）：
 *  - 洗分列＝與合計 total_out 同一組型別（{@see Sr02nSegmentEnum::withdrawalTypes()}）。
 *  - 洗分列有提款單（會員提款、代理自助洗分）→ 手續費＝withdrawal_orders.tax × rate（換成平台點數）；無提款單 → 0。
 *  - 實際金額＝變更點數 − 手續費；非洗分列（開分、充值、獎勵、拒絕洗分、補帳等）兩欄皆 null。
 *  - 被拒絕的洗分（提款單最終拒絕／退款、會員拒絕洗分，定義見 {@see AbstractSr02nSegment}）兩欄皆 null，不進兩個合計；
 *    總開分／總洗分照舊計入。
 *
 * @knowledge rules/report-sr02n
 */
class Sr02nReportService
{
    /** @var array<int, Sr02nSegmentInterface> 順序固定：主錢包放第一決定 UNION 欄位型別 */
    private array $segments;

    public function __construct(
        private Sr02nScopeResolver $scopeResolver,
        MainWalletSegment $mainWallet,
        GameWalletSegment $gameWallet,
        AgentCreditSegment $agentCredit,
        AgentWalletSegment $agentWallet,
    ) {
        $this->segments = [$mainWallet, $gameWallet, $agentCredit, $agentWallet];
    }

    /**
     * @return LengthAwarePaginator<int, object>
     */
    public function list(Agent $viewer, Sr02nFilter $filter, int $languageId, int $page, int $perPage): LengthAwarePaginator
    {
        $scope = $this->scopeResolver->resolve($viewer, $filter->agentUsername);
        $union = $scope->isEmpty ? null : $this->union($filter, $scope, $languageId, list: true);

        if ($union === null) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }

        // fromSub 而非 DB::raw＋mergeBindings：各段 join 帶綁定值時後者的參數會錯位
        $outer = DB::query()->fromSub($union, 's');
        $total = (clone $outer)->count();

        $rows = $outer->select('s.*')
            ->orderByDesc('s.created_at')
            ->orderByDesc('s.id')
            // 不同段的 id 可能相同：再以段別排序，確保跨頁順序穩定
            ->orderBy('s.segment')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (object $row): object => $this->attachMoney($row))
            ->all();

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    /**
     * @return array{total_in: string, total_out: string, total_tax: string, total_actual: string}
     */
    public function total(Agent $viewer, Sr02nFilter $filter, int $languageId): array
    {
        $scope = $this->scopeResolver->resolve($viewer, $filter->agentUsername);
        $union = $scope->isEmpty ? null : $this->union($filter, $scope, $languageId, list: false);

        $sums = $union === null
            ? null
            : DB::query()->fromSub($union, 's')
                ->selectRaw(implode(', ', array_map(
                    static fn (string $column): string => "COALESCE(SUM(s.{$column}), 0) as {$column}",
                    AbstractSr02nSegment::TOTAL_COLUMNS,
                )))
                ->first();

        $result = [];
        foreach (AbstractSr02nSegment::TOTAL_COLUMNS as $column) {
            $result[$column] = Common::numAdd('0', (string) ($sums->{$column} ?? '0'), Sr02nReportTypeSet::MONEY_SCALE);
        }

        /** @var array{total_in: string, total_out: string, total_tax: string, total_actual: string} $result */
        return $result;
    }

    /** 併入所有啟用段；無段可併回 null（結果必為空） */
    private function union(Sr02nFilter $filter, Sr02nScope $scope, int $languageId, bool $list): ?Builder
    {
        $parts = [];
        foreach ($this->segments as $segment) {
            if (!$segment->enabled($filter)) {
                continue;
            }
            $parts[] = $list
                ? $segment->listQuery($filter, $scope, $languageId)
                : $segment->totalQuery($filter, $scope, $languageId);
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return $union;
    }

    /**
     * 手續費與實際金額（PM 2026-09-30 定案 B）：
     * 非洗分列兩欄皆 null；洗分列有提款單時手續費＝tax × rate（tax 為會員幣別、point 為平台點數，換算後截到 4 位），
     * 無提款單時手續費 0；實際金額＝變更點數 − 手續費。
     * 被拒絕的洗分列（target/tickets#4493，段內 SQL 算出的 is_rejected＝1）會員或代理沒拿到錢，兩欄同非洗分列為 null；
     * 沒有拒絕概念的段 is_rejected 為 NULL，視同 0。
     *
     * 必須與合計 SQL 的 {@see AbstractSr02nSegment::convertedTax()}／sumActual() 同口徑（皆捨去到 4 位、排除同一批被拒絕列），
     * 由 Sr02nTotalTest::test_totals_match_list_rows 與 test_total_actual_equals_out_minus_tax 守護。
     */
    private function attachMoney(object $row): object
    {
        $segment = Sr02nSegmentEnum::from((string) $row->segment);
        $isRejected = (int) ($row->is_rejected ?? 0) === 1;

        if (!$segment->isWithdrawalType((int) $row->raw_type) || $isRejected) {
            $row->tax = null;
            $row->actual_point = null;

            return $row;
        }

        $scale = Sr02nReportTypeSet::MONEY_SCALE;
        $tax = $row->withdrawal_tax === null
            ? Common::numAdd('0', '0', $scale)
            : Common::numAdd('0', Common::numMul((string) $row->withdrawal_tax, (string) $row->withdrawal_rate, $scale), $scale);

        $row->tax = $tax;
        $row->actual_point = Common::numSub(Common::numAdd('0', (string) $row->point, $scale), $tax, $scale);

        return $row;
    }
}
