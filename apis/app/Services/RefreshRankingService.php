<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\General\OrientationEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorMaintainTypeEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\EventVendor;
use App\Models\EventVendorInfo;
use App\Models\Member;
use App\Models\Vendor;
use App\Vendors\Models\AbstractAccount;
use App\Vendors\Models\EventFumaswAccount;
use App\Vendors\Models\EventPgsswAccount;
use App\Vendors\Models\EventPpswAccount;
use App\Vendors\Models\EventUuslotswAccount;
use App\Vendors\Models\EventVpowerswAccount;
use App\Vendors\Models\EventWfswAccount;
use App\Vendors\Models\EventWudiswAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 刷分排行榜活動（`api/event/refresh_ranking/*`）
 *
 * 遷移自 API_Frontend RefreshRankingController；**tpower-master 獨有**，vw-master 完全
 * 沒有此 controller 也無路由。查證 tpower 正式 DB：目前仍有進行中的活動（agent 71541／
 * 71561），故完整收錄。
 *
 * `game_url`（依遊戲商啟動遊戲）改用既有 Driver 注入 EventVendorInfo／活動專用帳號重用
 * `getGameUrl()`（見 {@see \App\Vendors\BaseAbstractVendor::useLegacyEventContext()}），
 * 與 EventV2 無關。收錄舊版 `get_account()` 全部 7 個 third_party_code 分支
 * （PGSSW／UUSlot／VpowerSW／WF Gaming／PPSW／WudiSW／FumaSW）＋ WudiSW／FumaSW 的
 * 免盤費變體，不預設任何一家是死分支（見遷移文件 4-2）。
 */
class RefreshRankingService
{
    /** event_details.action_id：刷分排行榜活動 */
    private const int ACTION_ID = 13;

    /**
     * 活動專用帳號池的 Model，key 為 `vendors.third_party_code`。
     *
     * key **必須**是 third_party_code 而非 vendor_id：舊版 `get_account()` 就是以
     * third_party_code 分支，而一個 code 對應多個 vendor_id（聚合遊戲商共用同一個代碼，
     * 見 {@see Vendor::$third_party_code}）。例如 `PGSSW` 在正式環境同時涵蓋
     * Pegasus／BWG／TIGER9／JokerBaccarat 四個 vendor_id，`WUDISW`／`FUMASW` 各自涵蓋本體
     * 與免盤費變體——這些都共用同一張活動帳號表。改以 vendor_id 當 key 會漏掉除本體以外
     * 的所有 vendor_id。
     */
    private const array EVENT_ACCOUNT_MODELS = [
        'PGSSW' => EventPgsswAccount::class,
        'UUSSW' => EventUuslotswAccount::class,
        'VPOWERSW' => EventVpowerswAccount::class,
        'WFSW' => EventWfswAccount::class,
        'PPSW' => EventPpswAccount::class,
        'WUDISW' => EventWudiswAccount::class,
        'FUMASW' => EventFumaswAccount::class,
    ];

    public function __construct(
        protected VendorService $vendorService,
        protected EventService $eventService,
        protected WalletService $walletService,
    ) {}

    /**
     * event/refresh_ranking/list - 期數列表
     *
     * @return Collection<int, mixed>
     */
    public function getIssueList(Member $member, Agent $agent): Collection
    {
        return DB::table('events as e')
            ->join('event_details as ed', function ($join) {
                $join->on('ed.event_id', 'e.id')
                    ->where('ed.start_time', '<=', now()->toDateTimeString())
                    ->where('ed.status', 1)
                    ->where('ed.action_id', self::ACTION_ID);
            })
            ->join('event_refresh_rankings as err', function ($join) {
                $join->on('err.event_id', 'e.id')->on('err.event_detail_id', 'ed.id');
            })
            ->leftJoin('event_refresh_ranking_records as errr', function ($join) use ($member) {
                $join->on('errr.event_refresh_ranking_id', 'err.id')->where('errr.member_id', $member->id);
            })
            ->whereIn('e.agent_id', $agent->hierarchy_array)
            ->where('e.status', 1)
            ->orderByDesc('err.no')
            ->select([
                'e.id as event_id',
                'err.id as id',
                'err.no as no',
                DB::raw('IFNULL(errr.bet_amount, 0) as my_bet_amount'),
                DB::raw('DATE_ADD(ed.start_time, INTERVAL 8 HOUR) as start_time'),
                DB::raw('DATE_ADD(ed.end_time, INTERVAL 8 HOUR) as end_time'),
            ])
            ->get();
    }

    /**
     * event/refresh_ranking/leader_board - 排行榜
     *
     * 未在名次內時，於列表尾端補上自己的紀錄，名次顯示 `--`。帳號遮蔽規則見
     * {@see Common::maskUsernameKeepEnds()}（只保留頭尾各一字，tpower 獨有）。
     *
     * @return list<array{ranking: mixed, username: string, bet_amount: mixed, last_bet_time: mixed}>
     */
    public function getLeaderBoard(Member $member, Agent $agent, int $eventId, int $no, ?int $page, ?int $perPage): array
    {
        $this->eventService->assertEventAccessible($eventId, $agent);

        // 取得獎品數量作為預設每頁筆數
        $prizeNumber = (int) DB::table('prizes as p')
            ->join('prize_contents as pc', function ($join) {
                $join->on('pc.prize_id', 'p.id')->where('pc.status', 1);
            })
            ->where('p.event_id', $eventId)
            ->where('p.status', 1)
            ->sum('pc.number');

        $page = $page ?: 1;
        $perPage = $perPage ?: $prizeNumber;

        $query = $this->leaderBoardQuery($eventId, $no);

        // 無啟用獎項時 $prizeNumber 為 0：舊版 SUM() 回 NULL 使 take(null) 不產生 LIMIT，
        // 等同不分頁撈出全部名次；此處不可退化成 LIMIT 0，否則排行榜整張消失
        $rows = $perPage > 0 ? $query->forPage($page, $perPage)->get() : $query->get();

        $result = [];
        $hasSelf = false;
        foreach ($rows as $row) {
            if ((int) $row->member_id === $member->id) {
                $hasSelf = true;
            } else {
                $row->username = Common::maskUsernameKeepEnds((string) $row->username);
            }

            $result[] = [
                'ranking' => $row->ranking,
                'username' => $row->username,
                'bet_amount' => $row->bet_amount,
                'last_bet_time' => $row->last_bet_time,
            ];
        }

        if ($hasSelf || $rows->isEmpty()) {
            return $result;
        }

        // 自己不在名次內時補在最後
        $self = $this->leaderBoardQuery($eventId, $no, $member->id)->get();
        foreach ($self as $row) {
            $result[] = [
                'ranking' => '--',
                'username' => $row->username,
                'bet_amount' => $row->bet_amount,
                'last_bet_time' => $row->last_bet_time,
            ];
        }

        return $result;
    }

    /**
     * 排行榜查詢；帶 $memberId 時只取該會員（自己補位用）
     *
     * 主排行榜只列「有投注且已跑過批次計算」的紀錄；自己補位時舊版刻意不設這兩個條件
     * （見舊版 RefreshRankingController 的 $self_data），批次尚未計算時仍要讓自己以
     * `--` 出現在列表尾端，不可套用主排行榜的過濾條件把自己整筆濾掉。
     */
    private function leaderBoardQuery(int $eventId, int $no, ?int $memberId = null): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('event_refresh_rankings as err')
            ->join('event_refresh_ranking_records as errr', 'errr.event_refresh_ranking_id', 'err.id')
            ->join('members as m', function ($join) use ($memberId) {
                $join->on('m.id', 'errr.member_id')
                    ->when($memberId !== null, fn ($query) => $query->where('m.id', $memberId));
            })
            ->where('err.event_id', $eventId)
            ->where('err.no', $no)
            ->orderByDesc('errr.bet_amount')
            ->orderBy('errr.last_bet_time')
            ->select([
                'errr.ranking',
                'm.id as member_id',
                'm.username',
                DB::raw('TRUNCATE(errr.bet_amount, 2) as bet_amount'),
                DB::raw('DATE_ADD(errr.last_bet_time, INTERVAL 8 HOUR) as last_bet_time'),
            ]);

        if ($memberId === null) {
            $query->where('errr.bet_amount', '>', 0)->where('errr.is_calc', 1);
        }

        return $query;
    }

    /**
     * event/refresh_ranking/wallet_balance - 刷分排行榜錢包餘額
     *
     * 沒有錢包時（帳號在刷新後才建立）直接以活動設定的 `point` 建立一個。
     */
    public function getWalletBalance(Member $member, Agent $agent): string
    {
        $event = DB::table('events as e')
            ->join('event_details as ed', function ($join) {
                $join->on('ed.event_id', 'e.id')
                    ->where('ed.start_time', '<=', now()->toDateTimeString())
                    ->where('ed.end_time', '>', now()->toDateTimeString())
                    ->where('ed.status', 1)
                    ->where('ed.action_id', self::ACTION_ID);
            })
            ->join('event_refresh_rankings as err', function ($join) {
                $join->on('err.event_id', 'e.id')->on('err.event_detail_id', 'ed.id');
            })
            ->whereIn('e.agent_id', $agent->hierarchy_array)
            ->where('e.status', 1)
            ->select(['err.id as id', 'ed.point as point'])
            ->first();

        if (!$event) {
            throw new ApiException(__('error_message.1901'));
        }

        // 新建時以活動設定的 point 當起始餘額；錢包已存在時 initialBalance 不生效、
        // 既有餘額不受影響（見 WalletService::getOrCreateWallet 的說明）。
        $wallet = $this->walletService->getOrCreateWallet(
            $member->id,
            WalletEnum::CODE_EVENT_RANKING,
            initialBalance: Common::numAdd('0', (string) $event->point, 4),
        );

        return (string) $wallet->balance;
    }

    /**
     * event/refresh_ranking/vendor - 可玩的遊戲商列表
     *
     * @return Collection<int, mixed>
     */
    public function getVendorList(Agent $agent): Collection
    {
        $eventVendorAgentId = $this->findEventVendorAgentId($agent);
        if ($eventVendorAgentId === 0) {
            // 沒有設定的遊戲商
            throw new ApiException(__('error_message.1426'));
        }

        $maintainedVendorIds = $this->vendorService->getMaintenanceVendorIds(
            agent: $agent,
            status: [VendorMaintainTypeEnum::BOTH_CLOSED, VendorMaintainTypeEnum::GAME_CLOSED],
        );

        $vendors = DB::table('vendors as v')
            ->join('event_vendors as ev', function ($join) use ($eventVendorAgentId) {
                $join->on('ev.vendor_id', 'v.id')
                    ->where('ev.agent_id', $eventVendorAgentId)
                    ->whereIn('ev.status', [1, 4]);
            })
            ->join('event_vendor_infos as evi', function ($join) {
                $join->on('evi.id', 'ev.event_vendor_info_id')->where('evi.status', 1);
            })
            ->where('v.status', 1)
            ->select([
                'v.id',
                DB::raw('IFNULL(evi.name, v.name) as name'),
                DB::raw('IFNULL(evi.image, v.image) as image'),
                DB::raw('IFNULL(evi.app_icon, v.app_icon) as app_icon'),
                'v.only_one_game',
                'v.need_login',
                'ev.status',
            ])
            ->get();

        foreach ($vendors as $vendor) {
            if (in_array((int) $vendor->id, $maintainedVendorIds, true)) {
                $vendor->status = VendorMaintainTypeEnum::WALLET_CLOSED->value;
            }
        }

        return $vendors;
    }

    /**
     * event/refresh_ranking/game - 指定遊戲商的遊戲列表
     *
     * @return Collection<int, mixed>
     */
    public function getGameList(Agent $agent, int $vendorId): Collection
    {
        $eventVendorAgentId = $this->findEventVendorAgentId($agent);
        if ($eventVendorAgentId === 0) {
            // 沒有設定的遊戲商
            throw new ApiException(__('error_message.1426'));
        }

        return DB::table('games as g')
            ->join('event_vendor_info_games as evig', function ($join) {
                $join->on('evig.game_id', 'g.id')->where('evig.status', 1);
            })
            ->join('event_vendor_infos as evi', function ($join) {
                $join->on('evi.id', 'evig.event_vendor_info_id')->where('evi.status', 1);
            })
            ->join('event_vendors as ev', function ($join) use ($vendorId, $eventVendorAgentId) {
                $join->on('ev.event_vendor_info_id', 'evi.id')
                    ->where('ev.vendor_id', $vendorId)
                    ->where('ev.agent_id', $eventVendorAgentId)
                    ->where('ev.status', 1);
            })
            ->where('g.status', 1)
            ->select(['g.id', 'g.name', 'g.image', 'g.type_id', 'g.is_hot', 'g.is_new'])
            ->get();
    }

    /**
     * event/refresh_ranking/awards - 獎項列表
     *
     * @return array{content: string|null, prizes: Collection<int, mixed>}
     */
    public function getAwards(Agent $agent, int $eventId, int $languageId): array
    {
        $this->eventService->assertEventAccessible($eventId, $agent);

        $content = DB::table('events as e')
            ->leftJoin('event_names as en', function ($join) use ($languageId) {
                $join->on('en.event_id', 'e.id')->where('en.language_id', $languageId);
            })
            ->where('e.id', $eventId)
            ->where('e.status', 1)
            ->select(DB::raw('(CASE WHEN en.content is NULL THEN e.content ELSE en.content END) as content'))
            ->first();

        $now = now()->toDateTimeString();
        $prizes = DB::table('events as e')
            ->join('event_details as ed', function ($join) use ($now) {
                $join->on('ed.event_id', 'e.id')
                    ->where('ed.status', 1)
                    ->where('ed.action_id', self::ACTION_ID)
                    ->where('ed.start_time', '<=', $now)
                    ->where('ed.end_time', '>=', $now);
            })
            ->join('prizes as p', function ($join) {
                $join->on('p.event_id', 'e.id')->where('p.status', 1);
            })
            ->join('prize_contents as pc', function ($join) {
                $join->on('pc.prize_id', 'p.id')->where('pc.status', 1);
            })
            ->leftJoin('prize_content_names as pcn', function ($join) use ($languageId) {
                $join->on('pcn.prize_content_id', 'pc.id')->where('pcn.language_id', $languageId);
            })
            ->where('e.id', $eventId)
            ->where('e.status', 1)
            ->orderBy('pc.level')
            ->select([
                'e.id as event_id',
                DB::raw('IFNULL(pcn.name, pc.name) AS name'),
                'pc.level',
                'pc.number',
                'pc.point',
            ])
            ->get();

        // 舊版查無活動時直接讀 null 屬性而致命
        return ['content' => $content->content ?? null, 'prizes' => $prizes];
    }

    /**
     * event/refresh_ranking/game_url - 依遊戲商啟動遊戲
     *
     * 遷移自 API_Frontend RefreshRankingController::game_url。活動專用帳號池
     * （`event_pgssw_accounts` 等 4 張表）與會員自己的一般遊戲商帳號完全獨立，
     * 首次使用時比照 {@see \App\Vendors\BaseAbstractVendor::getVendorAccount()} 的併發防護
     * 手法（Cache::lock ＋ deadlock 有限重試）認領一筆未綁定帳號，而非舊版的
     * `DB::beginTransaction()` 陽春鎖法。與 EventV2（`app/Services/EventV2/`）無關。
     *
     * 支援舊版 `get_account()` 全部 7 個 `third_party_code`（PGSSW／UUSSW／VPOWERSW／
     * WFSW／PPSW／WUDISW／FUMASW），對應表見 {@see self::EVENT_ACCOUNT_MODELS}。
     *
     * @return array{result: VendorGameUrlResultData, open_window: int}
     */
    public function getGameUrl(
        Member $member,
        Agent $agent,
        int $gameId,
        LanguageEnum $language,
        DeviceTypeEnum $deviceType,
        string $exitUrl,
        string $ip,
        OrientationEnum $orientation = OrientationEnum::UNKNOWN,
    ): array {
        $eventVendorAgentId = $this->findEventVendorAgentId($agent);
        if ($eventVendorAgentId === 0) {
            // 沒有設定的遊戲商
            throw new ApiException(__('error_message.1426'));
        }

        $game = DB::table('games as g')
            ->join('event_vendor_info_games as evig', function ($join) {
                $join->on('evig.game_id', 'g.id')->where('evig.status', 1);
            })
            ->join('event_vendor_infos as evi', function ($join) {
                $join->on('evi.id', 'evig.event_vendor_info_id')->where('evi.status', 1);
            })
            ->join('event_vendors as ev', function ($join) use ($eventVendorAgentId) {
                $join->on('ev.event_vendor_info_id', 'evi.id')
                    ->where('ev.agent_id', $eventVendorAgentId)
                    ->where('ev.status', 1);
            })
            ->where('g.id', $gameId)
            ->where('g.status', 1)
            ->select(['g.id', 'g.game_type', 'g.open_window', 'g.vendor_id', 'evi.id as event_vendor_info_id'])
            ->first();

        if (!$game) {
            // 遊戲不存在
            throw new ApiException(__('error_message.1406'));
        }

        $vendorInfo = EventVendorInfo::find($game->event_vendor_info_id);
        if (!$vendorInfo) {
            // 遊戲商不存在
            throw new ApiException(__('error_message.1426'));
        }

        // 帳號池要以 third_party_code 決定，故先取 vendor（順序同舊版：先 Vendor::find()
        // 再 get_account()，不可改成先建 Driver——getDriver() 對未配置的遊戲商會先拋
        // VendorNotFoundException，蓋掉這裡要回的 9994）
        $vendor = Vendor::find((int) $game->vendor_id);
        if (!$vendor) {
            // 遊戲商不存在
            throw new ApiException(__('error_message.1426'));
        }

        $eventAccount = $this->claimEventAccount($vendor, (int) $vendorInfo->id, $member);

        $vendorDriver = $this->vendorService->getDriver((int) $game->vendor_id);
        $vendorDriver->useLegacyEventContext($vendorInfo, $eventAccount);

        $params = VendorGameUrlParamsData::from([
            'gameCode' => $game->game_type,
            'deviceType' => $deviceType,
            'orientation' => $orientation,
            'language' => $language,
            'exitUrl' => $exitUrl,
            'ip' => $ip,
        ]);

        return [
            'result' => $vendorDriver->getGameUrl($params),
            // 取 games.open_window（game-level）而非 vendors.open_window：舊版回的是
            // `$game->open_window`，且 open_window 本身是逐款遊戲同步寫入的屬性
            // （見 GameListSyncService），同一遊戲商底下各遊戲的值可以不同
            'open_window' => (int) $game->open_window,
        ];
    }

    /**
     * 認領活動專用帳號：先查會員自己已綁定的一筆，查無則在同一把鎖底下搶一筆
     * `member_id = 0`（未綁定）的帳號並更新綁定。與一般遊戲商帳號池（`vendor_info_id`／
     * `getVendorAccount()`）完全獨立，僅欄位命名（`event_vendor_info_id`）與併發手法相同。
     */
    private function claimEventAccount(Vendor $vendor, int $eventVendorInfoId, Member $member): AbstractAccount
    {
        // 帳號池以 third_party_code 決定（同一 code 的聚合遊戲商共用同一張表，池子再以
        // event_vendor_info_id 分隔，不同商戶不會互搶）。
        // 錯誤碼與舊版有意不同：舊版 game_url() 實際可達的是 get_account() 的 1005
        // （"Account doesn't exist"，其第二層 switch 的 9994 為死代碼）；此處拋 9994
        // （"Vendor 不存在"）較貼合「該遊戲商不支援活動帳號池」的語意。
        $accountClass = self::EVENT_ACCOUNT_MODELS[$vendor->third_party_code]
            ?? throw new ApiException(__('error_message.9994'));

        $lock = Cache::lock("legacy_event_account_bind:{$eventVendorInfoId}:{$member->id}", seconds: 10);
        $lock->block(seconds: 5);

        $maxAttempts = 3;
        $attempt = 0;

        try {
            while (++$attempt <= $maxAttempts) {
                try {
                    DB::beginTransaction();

                    // Query 1: 取得會員已綁定的帳號
                    $account = $accountClass::where('event_vendor_info_id', $eventVendorInfoId)
                        ->where('member_id', $member->id)
                        ->first();

                    if ($account) {
                        DB::commit();

                        return $account;
                    }

                    // Query 2: 認領未綁定帳號（FOR UPDATE SKIP LOCKED 避免並發爭搶同一池子時 Deadlock）
                    $account = $accountClass::where('event_vendor_info_id', $eventVendorInfoId)
                        ->where('member_id', 0)
                        ->orderBy('id')
                        ->lock('for update skip locked')
                        ->first();

                    if (!$account) {
                        // 找不到可認領的帳號
                        throw new ApiException(__('error_message.9993'));
                    }

                    $account->update(['member_id' => $member->id]);

                    DB::commit();

                    // 與 BaseAbstractVendor::getVendorAccount() 一致留下綁定軌跡，
                    // 事件帳號池用完或綁定異常時才查得到是誰在什麼時候認領了哪一筆
                    Log::info('刷分排行榜活動帳號綁定成功', [
                        'vendor_id' => $vendor->id,
                        'third_party_code' => $vendor->third_party_code,
                        'event_vendor_info_id' => $eventVendorInfoId,
                        'account_id' => $account->id,
                        'member_id' => $member->id,
                    ]);

                    return $account;
                } catch (QueryException $e) {
                    DB::rollBack();

                    $sqlState = $e->errorInfo[0] ?? null;
                    $mysqlErrno = (int) ($e->errorInfo[1] ?? 0);
                    $isDeadlock = $sqlState === '40001' || $mysqlErrno === 1213;

                    if ($isDeadlock && $attempt < $maxAttempts) {
                        usleep($attempt * 50_000); // 50ms / 100ms

                        continue;
                    }

                    throw $e;
                } catch (Throwable $e) {
                    // 上面的 ApiException（找不到可認領帳號）等非 QueryException 也必須關掉交易，
                    // 否則會帶著未 rollback 的交易穿透 finally，後續寫入全落在懸空交易裡
                    DB::rollBack();

                    throw $e;
                }
            }
        } finally {
            $lock->release();
        }

        // 此行不可達：while 迴圈每次迭代必定透過 return 或 throw 離開
        throw new ApiException(__('error_message.9993'));
    }

    /**
     * 找出代理層級中最近一個有設定 EventVendor 的代理 id（由近到遠）；查無設定回傳 0
     *
     * 舊版走 Agent.hierarchy（JSON 欄位）並排除頭尾兩個 sentinel 值；此處改走
     * apis「新版」的 hierarchy_array（語意相同、由近到遠含自己），不再讀legacy欄位。
     */
    private function findEventVendorAgentId(Agent $agent): int
    {
        $configuredAgentIds = EventVendor::query()
            ->whereIn('agent_id', $agent->hierarchy_array)
            ->pluck('agent_id')
            ->all();

        foreach ($agent->hierarchy_array as $agentId) {
            if (in_array($agentId, $configuredAgentIds)) {
                return (int) $agentId;
            }
        }

        return 0;
    }
}
