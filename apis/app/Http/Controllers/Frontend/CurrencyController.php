<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Frontend\CurrencyResource;
use App\Models\Currency;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

#[Group('Currency (幣種)')]
class CurrencyController extends Controller
{
    /**
     * 幣種列表
     *
     * OLD: /api/currencies
     */
    public function list(Request $request)
    {
        $userData = $this->getUserContext();

        $languageId = $userData->languageId;

        $list = Cache::remember(CacheKeys::currencyList($languageId), CacheTtl::ONE_HOUR->value, function () use ($languageId) {
            return Currency::query()->with('names', function ($query) use ($languageId) {
                $query->where('language_id', $languageId);
            })
                ->where('status', 1)
                ->get();
        });

        $resource = CurrencyResource::collection($list);

        return ApiResponse::success($resource);
    }
}
