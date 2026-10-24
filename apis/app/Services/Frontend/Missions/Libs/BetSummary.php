<?php

declare(strict_types=1);

namespace App\Services\Frontend\Missions\Libs;

use App\Enums\Missions\MissionConditionTypeEnum;
use App\Models\Game;
use App\Models\Mission;
use App\Services\Frontend\Missions\DTOs\ActionConfigDTO;
use App\Services\Frontend\Missions\DTOs\ConditionConfigDTO;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class BetSummary implements LibInterface
{
    public function exec(Mission $mission, string $summaryStartDate, string $summaryEndDate): Builder
    {
        $configs = $mission->info;
        if (empty($configs)) {
            throw new \Exception('Configs empty');
        }

        $conditionConfig = new ConditionConfigDTO($configs['condition_config'] ?? []);
        $actionConfig = new ActionConfigDTO($configs['action_config'] ?? []);

        $query = DB::table('total_bet_records')
            ->where('total_bet_records.created_at', '>=', $summaryStartDate)
            ->where('total_bet_records.created_at', '<', $summaryEndDate);

        if (isset($actionConfig->extraDatas['game'])) {
            $gameTypes = $actionConfig->extraDatas['game']['game_type'];
            $vendorIds = $actionConfig->extraDatas['game']['vendor_id'];

            $availableGameIdsQuery = Game::query();

            if (!empty($vendorIds) && !in_array(0, $vendorIds)) {
                $query->whereIn('vendor_id', $vendorIds);
                $availableGameIdsQuery->whereIn('vendor_id', $vendorIds);
            }

            if (!empty($gameTypes) && !in_array(0, $gameTypes)) {
                $availableGameIds = $availableGameIdsQuery->whereIn('type_id', $gameTypes)
                    ->pluck('id')
                    ->toArray();
                $query->whereIn('game_id', $availableGameIds);
            }
        }

        switch ($conditionConfig->type) {
            case MissionConditionTypeEnum::AMOUNT->value:
                $query = $query->select('member_id', DB::raw('SUM(valid_bet) as progress'));
                break;

            case MissionConditionTypeEnum::TIMES->value:
                $query = $query->select('member_id', DB::raw('COUNT(*) as progress'));
                break;

            default:
                throw new \Exception("Invalid condition type: {$conditionConfig->type}");
        }

        return $query->groupBy(['member_id']);
    }
}
