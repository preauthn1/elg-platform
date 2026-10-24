<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ThreeHierarchyProportion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ThreeHierarchyService
{
    public function getRule(int $agentGroupId, int $languageId): Collection
    {
        return ThreeHierarchyProportion::from('three_hierarchy_proportions as thp')
            ->join('vendors as v', function ($join) {
                $join->on('v.id', 'thp.vendor_id')
                    ->where('v.status', 1);
            })
            ->join('game_types as gt', function ($join) {
                $join->on('gt.id', 'thp.game_type_id')
                    ->where('gt.status', 1);
            })
            ->leftJoin('game_type_names as gtn', function ($join) use ($languageId) {
                $join->on('gtn.game_type_id', 'gt.id')
                    ->where('gtn.language_id', $languageId);
            })
            ->where('thp.agent_group_id', $agentGroupId)
            ->orderBy('thp.type', 'ASC')
            ->orderBy('thp.game_type_id', 'ASC')
            ->orderBy('thp.vendor_id', 'ASC')
            ->select(
                'v.id as vendor_id',
                'v.name as vendor_name',
                'v.image as vendor_image',
                DB::raw('IFNULL(gtn.name, gt.name) as game_type_name'),
                'thp.game_type_id',
                'thp.type',
                DB::raw('(thp.proportion_1 * 100) as proportion_1'),
                DB::raw('(thp.proportion_2 * 100) as proportion_2'),
                DB::raw('(thp.proportion_3 * 100) as proportion_3')
            )
            ->get();
    }
}
