<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\AdvertiseImageData;
use App\Models\AdvertiseLanguage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdvertiseService
{
    /**
     * @return Collection<int, AdvertiseImageData>
     */
    public function getImages(array $adIds, int $languageId): Collection
    {
        $adLanguages = AdvertiseLanguage::whereIn('language_id', [1, $languageId])
            ->whereIn('advertise_id', $adIds)
            ->select(
                'advertise_id',
                DB::raw('MAX( IF(language_id = 1, advertise_image_id, 0) ) as default_advertise_image_id'),
                DB::raw('MAX( IF(language_id = 1, advertise_image_pc_id, 0) ) as default_advertise_image_pc_id'),
                DB::raw('MAX( IF(language_id != 1, advertise_image_id, 0) ) as advertise_image_id'),
                DB::raw('MAX( IF(language_id != 1, advertise_image_pc_id, 0) ) as advertise_image_pc_id')
            )
            ->groupBy('advertise_id')
            ->get();

        foreach ($adLanguages as $adImage) {

            if (!$adImage->advertise_image_id) {
                $adImage->advertise_image_id = $adImage->default_advertise_image_id;
            }

            if (!$adImage->advertise_image_pc_id) {
                $adImage->advertise_image_pc_id = $adImage->default_advertise_image_pc_id;
            }
        }

        // eager loading here, not using 'with' in query since we need to get default image if no language image first.
        $adLanguages->load('advertiseImage:id,image_url', 'advertiseImagePc:id,image_url');

        $adImages = $adLanguages->map(function ($adImage) {
            return AdvertiseImageData::from([
                'advertiseId' => $adImage->advertise_id,
                'imageUrl' => $adImage->advertiseImage->first()->image_url ?? null,
                'imagePcUrl' => $adImage->advertiseImagePc->first()->image_url ?? null,
            ]);
        });

        return $adImages;
    }
}
