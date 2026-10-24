<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\DomainRequest;
use App\Http\Requests\Frontend\Marquee\TextsRequest;
use App\Http\Resources\Frontend\Marquee\WinPointsResource;
use App\Models\AgentDomain;
use App\Models\MarqueeText;
use App\Models\MarqueeWinPointRecord;
use App\Models\TotalBetRecord;
use App\Services\MarqueeService;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MarqueeController extends Controller
{
    public function __construct(
        private MarqueeService $marqueeService,
    ) {}

    #[HeaderParameter('Authorization', description: 'Token', required: false, type: 'string')]
    /**
     * 文字跑馬燈
     *
     * OLD: /api/marquee/text/getList
     */
    public function texts(TextsRequest $request)
    {
        $domain = $request->domain;
        $location = $request->location ?? 0;

        $userData = $this->getUserContext();
        $languageId = $userData->languageId;

        $agent = $userData->agent;

        if (!$agent) {
            return ApiResponse::error(__('error_message.1213'));
        }

        $agentIds = $agent->hierarchy_array;

        $cacheKey = CacheKeys::agentMarquee($domain, $languageId, $location);

        $data = Cache::remember($cacheKey, CacheTtl::DEFAULT->value, function () use ($agentIds, $location) {
            $now = Carbon::now();

            $marqueeTexts = MarqueeText::query()
                ->with([
                    'marqueeTextContent:id,marquee_text_id,content',
                ])
                ->select([
                    'id',
                    'type',
                    'location',
                ])
                ->when($location > 0, fn ($query) => $query->where('location', $location))
                ->where('status', 1)
                ->where('start_time', '<=', $now)
                ->where('end_time', '>=', $now)
                ->whereIn('agent_id', $agentIds)
                ->whereNotIn('exclude_agent_id', $agentIds)
                ->get();

            return $marqueeTexts->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->type,
                'location' => $item->location,
                'content' => $item->content,
            ])->toArray();
        });

        return ApiResponse::success($data);
    }

    #[HeaderParameter('Authorization', description: 'Token', required: false, type: 'string')]
    /**
     * 贏分跑馬燈列表
     *
     * OLD: /api/marquee/win_point/getList
     *
     * 1. 根據後臺設定的vendor與觸發金額進行篩選，從total_bet_record取前60分鐘符合條件的前30筆 (根據payout_time)
     * 2. 將上述資料寫入marquee_win_point_records, 每個代理最多只保留最新的30筆 (根據payout_time) 這是為了確保前60分鐘若沒人達標, 也有舊資料可以顯示跑馬燈
     * 3. 透過快取$update_time_key內的時間，判斷是否需要刷新內容，目前刷新時間大約取3分鐘
     */
    public function winPoints(DomainRequest $request)
    {
        $userData = $this->getUserContext();
        $agent = $userData->agent;
        $member = $userData->member;

        if (!$agent) {
            return ApiResponse::error(__('error_message.1213'));
        }

        $domain = $request->input('domain');
        $generalAgentDomain = AgentDomain::where('domain', $domain)->first();

        $triggerList = $this->marqueeService->getTriggerList($agent);
        if ($triggerList->isEmpty()) {
            return ApiResponse::success([]);
        }

        $cacheKey = CacheKeys::marqueeWinPoints($domain, $userData->languageId, $member !== null ? (string) $member->parent : null);

        $cacheTtl = rand(-10, 10) + CacheTtl::ONE_HOUR->value;

        $records = Cache::safeRemember($cacheKey, $cacheTtl, function () use ($agent, $generalAgentDomain, $triggerList, $userData) {
            $totalBetRecordData = TotalBetRecord::query()
                ->where('payout_time', '>=', Carbon::now()->subMinutes(60)->toDateTimeString())
                ->whereHas('member.agent', fn ($q) => $q
                    ->where('hierarchy_path', 'like', "$agent->hierarchy_path/%")
                    ->orWhere('id', $agent->id)
                )
                ->where(function ($query) use ($triggerList) {
                    foreach ($triggerList as $val) {
                        $query->orWhere(fn ($subQuery) => $subQuery
                            ->where('vendor_id', $val->vendor_id)
                            ->whereRaw("(bet + win_lose) >= $val->trigger_amount")
                        );
                    }
                })
                ->select(
                    'id',
                    DB::raw('ROUND(bet + win_lose,2) AS win'),
                    'member_id',
                    'vendor_id',
                    'payout_time',
                    'game_id'
                )
                ->orderBy('payout_time', 'DESC')
                ->limit(30)
                ->get();

            // TODO: 或許不應該在這裡做
            // 鎖住資料，避免一小時內無人達標導致顯示空資料
            foreach ($totalBetRecordData as $item) {
                MarqueeWinPointRecord::updateOrCreate([
                    'total_bet_record_id' => $item->id,
                    'win' => $item->win,
                    'agent_id' => $agent->id,
                    'vendor_id' => $item->vendor_id,
                    'payout_time' => $item->payout_time,
                    'member_id' => $item->member_id,
                    'game_id' => $item->game_id,
                ]);
            }

            $records = MarqueeWinPointRecord::query()
                ->with([
                    'member:id,nickname',
                    'game:id,name,image',
                    'game.gameNames' => fn ($q) => $q
                        ->where('language_id', $userData->languageId)
                        ->select('id', 'game_id', 'name'),
                ])
                ->has('game')
                ->has('member')
                ->where(function ($query) use ($triggerList) {
                    foreach ($triggerList as $val) {
                        $query->orWhere(fn ($subQuery) => $subQuery
                            ->where('vendor_id', $val->vendor_id)
                            ->where('win', '>=', $val->trigger_amount)
                        );
                    }
                })
                ->where(fn ($q) => $q
                    ->where('agent_id', $agent->id)
                    ->when($agent->id != $generalAgentDomain->agent_id, fn ($q) => $q   // 避免新代理的跑馬燈顯示為空, 需加入總代的跑馬燈內容(訪客視角)
                        ->orwhere('agent_id', $generalAgentDomain->agent_id)
                    )
                )
                ->orderByDesc('payout_time')
                ->groupBy('total_bet_record_id')
                ->limit(30) // 最多30筆
                ->get();

            $top30RecordIds = MarqueeWinPointRecord::where('agent_id', $agent->id)
                ->orderByDesc('payout_time')
                ->limit(30)
                ->pluck('total_bet_record_id');

            MarqueeWinPointRecord::where('agent_id', $agent->id)
                ->whereNotIn('total_bet_record_id', $top30RecordIds)
                ->delete();

            return $records;
        });

        return ApiResponse::success(WinPointsResource::collection($records));
    }
}
