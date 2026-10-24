<?php

declare(strict_types=1);

namespace App\Services\Frontend\Missions\Libs;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Missions\MissionConditionTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Models\Mission;
use App\Services\Frontend\Missions\DTOs\ConditionConfigDTO;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DepositSummary implements LibInterface
{
    /**
     * @var string
     */
    private $summaryStartDate;

    /**
     * @var string
     */
    private $summaryEndDate;

    /**
     * @var ConditionConfigDTO
     */
    private $conditionConfig;

    public function exec(Mission $mission, string $summaryStartDate, string $summaryEndDate): Builder
    {
        $this->summaryStartDate = $summaryStartDate;
        $this->summaryEndDate = $summaryEndDate;

        $configs = $mission->info;
        if (empty($configs)) {
            throw new \Exception('Configs empty');
        }

        $this->conditionConfig = new ConditionConfigDTO($configs['condition_config'] ?? []);

        // target/tickets#4352：後台開分只在代理組別設置開啟 BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT 時才計入；
        // COALESCE 的 '0' 需與 agent_setting_defines 的 default_value 同步。
        $transactionLogQuery = DB::table('transaction_logs')->where(function ($query) {
            $query->where('type', TransactionEnum::ORDER_DEPOSIT->value)
                ->orWhere(function ($query) {
                    $query->where('type', TransactionEnum::DEPOSIT->value)
                        ->whereExists(function ($query) {
                            $query->select(DB::raw(1))
                                ->from('members')
                                ->join('agents', 'agents.id', '=', 'members.parent')
                                ->leftJoin('agent_customized_settings', function ($join) {
                                    $join->on('agent_customized_settings.agent_group_id', '=', 'agents.customized_setting_group_id')
                                        ->where('agent_customized_settings.setting_key', AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT);
                                })
                                ->whereColumn('members.id', 'transaction_logs.member_id')
                                ->whereRaw("COALESCE(agent_customized_settings.value, '0') = '1'");
                        });
                });
        });
        $transactionLogQuery = $this->completeQuery($transactionLogQuery);

        $walletTransactionLogQuery = DB::table('wallet_transaction_logs')->where('type', WalletTransactionEnum::GAME_WALLET_ORDER_DEPOSIT->value);
        $walletTransactionLogQuery = $this->completeQuery($walletTransactionLogQuery);

        $combinedQuery = $transactionLogQuery->unionAll($walletTransactionLogQuery);

        return DB::table(DB::raw("({$combinedQuery->toSql()}) as combined"))
            ->mergeBindings($combinedQuery)
            ->select('member_id', DB::raw('SUM(progress) as progress'))
            ->groupBy(['member_id']);
    }

    private function completeQuery(Builder $query): Builder
    {
        $query = $query->where('status', 1000)
            ->where('created_at', '>=', $this->summaryStartDate)
            ->where('created_at', '<', $this->summaryEndDate);

        switch ($this->conditionConfig->type) {
            case MissionConditionTypeEnum::AMOUNT->value:
                $query = $query->select('member_id', DB::raw('SUM(point) as progress'));
                break;

            case MissionConditionTypeEnum::TIMES->value:
                $query = $query->select('member_id', DB::raw('COUNT(*) as progress'));
                break;

            case MissionConditionTypeEnum::FIX_NUMBER_TIMES->value:
                if (empty($this->conditionConfig->extraDatas['fix_number'])) {
                    throw new \Exception('Fix number not found');
                }

                $query = $query->where('point', '>=', $this->conditionConfig->extraDatas['fix_number'])
                    ->select('member_id', DB::raw('COUNT(id) as progress'));

                break;
            default:
                throw new \Exception("Invalid condition type: {$this->conditionConfig->type}");
        }

        $query = $query->groupBy(['member_id']);

        return $query;
    }
}
