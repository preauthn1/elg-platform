<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InformationTypeEnum;
use App\Enums\LanguageEnum;
use Illuminate\Support\Facades\DB;

class InformationService
{
    public function getInformationList($agentGroupId, $languageId)
    {
        return DB::table('informations as i')
            ->leftJoin('information_languages as dil', function ($join) {
                $join->on('dil.information_id', 'i.id')
                    ->where('dil.language', LanguageEnum::EN->value);
            })
            ->leftJoin('information_languages as il', function ($join) use ($languageId) {
                $join->on('il.information_id', 'i.id')
                    ->where('il.language', $languageId);
            })
            ->leftJoin('games as g', function ($join) {
                $join->where('i.type', InformationTypeEnum::GAME->value)
                    ->whereRaw('g.id = (i.content * 1)');
            })
            ->where('i.agent_group_id', $agentGroupId)
            ->where('i.status', 1)
            ->select(
                'i.id as id',
                'i.type as type',
                DB::raw('CASE WHEN i.type = ' . InformationTypeEnum::GAME_TYPE->value . ' THEN i.content END AS game_type_id'),
                DB::raw('CASE WHEN i.type = ' . InformationTypeEnum::GAME->value . ' THEN i.content END AS game_id'),
                DB::raw('CASE i.type WHEN ' . InformationTypeEnum::VENDOR->value . ' THEN i.content WHEN ' . InformationTypeEnum::GAME->value . ' THEN g.vendor_id END AS vendor_id'),
                DB::raw('CASE i.type WHEN ' . InformationTypeEnum::URL->value . ' THEN i.content WHEN ' . InformationTypeEnum::INTERNAL_URL->value . ' THEN i.content END AS url'),
                DB::raw('IFNULL(il.image, dil.image) as image'),
                DB::raw('IFNULL(il.image_pc, dil.image_pc) as image_pc'),
                DB::raw('IFNULL(i.description, \'\') as description'),
                'i.open_window'
            )
            ->orderBy('i.sort', 'ASC')
            ->get();
    }
}
