<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\VendorService;
use App\Helpers\Common;
use App\Helpers\SQLHelper;
use App\Enums\AgentCustomizedSettingKeyEnum;
use App\Enums\VendorEnum;
use App\Models\DBNamedLock;
use App\Models\SummaryBetRecord;
use App\Models\Game;
use DB;

class SummaryBetRecordCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'summary_bet_record {--start=} {--vendor=}'; //php artisan summary_bet_record --start=2019-08-01@00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '遊戲記錄每個人每小時每款遊戲結算';

    protected $service;
    protected $exclude_games = array();
    protected $v_jackpot_rate = array();
    protected $game_fee_rate = array();

    protected $sp_run_specify_time = array(); // 需要特別再跑一次sp的時間

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->service = new VendorService();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $start = $this->option('start')
            ? str_replace('@', ' ', $this->option('start')) . ':00:00'
            : date('Y-m-d H:00:00', strtotime('-1 Hour'));
        $end = $this->option('start')
            ? str_replace('@', ' ', $this->option('start')) . ':59:59.999999'
            : date('Y-m-d H:59:59.999999', strtotime('-1 Hour'));
        $vendor = $this->option('vendor')
            ? $this->option('vendor') : 0;
        echo $start . '~' . $end . PHP_EOL;
        Common::log(0, 'start' . $start . '~' . $end . ' - ' . $this->option('vendor'), 'summary_bet_record');

        //取得轉換率為0的遊戲
        $this->exclud_games = Game::where('valid_bet_rate', 0)->pluck('id')->toArray();

        //取得vjackpot的
        $rate = DB::table('agent_customized_settings')
            ->where('setting_key', AgentCustomizedSettingKeyEnum::V_JACKPOT_RATE)
            ->select('agent_group_id', 'value')
            ->get();
        foreach($rate as $row) {
            $this->v_jackpot_rate[$row->agent_group_id] = $row->value;
        }

        //遊戲盤費
        $game_fee = DB::table('vendor_infos as vi')
            ->join('agent_vendors as av', 'av.vendor_info_id', 'vi.id')
            ->join('agents as a', 'a.id','av.agent_id')
            ->groupBy('vi.vendor_id', 'a.customized_setting_group_id')
            ->orderBy('a.customized_setting_group_id', 'desc')
            ->orderBy('vi.vendor_id', 'desc')
            ->select(
                'vi.vendor_id',
                'a.customized_setting_group_id',
                'vi.cost'
            )
            ->get();

        foreach($game_fee as $row) {
            $this->game_fee_rate[$row->customized_setting_group_id][$row->vendor_id] = $row->cost;
        }

        //取得三方遊戲代碼
        $third_party_code = ($vendor > 0)
            ? $this->service->getThirdPartyCodeByVendorId($vendor)
            : 'ALL';
        echo 'third_party_code:' . $third_party_code . PHP_EOL;

        if (in_array($third_party_code, [VendorEnum::CODE_VPOWER, 'ALL'])) {
            $this_vendor = VendorEnum::VPOWER;
            echo 'Vpower' . PHP_EOL;
            $records = DB::table('vpower_bet_records as b')
                ->join('vpower_accounts as v', 'b.username', 'v.username')
                ->join('members as m', 'm.id', 'v.member_id')
                ->join('agents as a', 'a.id', 'm.parent')
                ->leftJoin('games as g', function ($join) use ($this_vendor) {
                    $join->on('g.remark', 'b.gameid')
                        ->where('g.vendor_id', VendorEnum::VPOWER);
                })
                ->whereBetween('b.utc_time', [$start, $end])
                ->select(
                    'm.id as member_id',
                    'b.wallet_type_id',
                    'g.id as game_id',
                    DB::raw('SUM(b.pay) as bet'),
                    DB::raw('SUM(b.win-b.pay) as win_lose'),
                    'a.percentage',
                    'a.customized_setting_group_id'
                )
                ->groupBy('m.id')
                ->groupBy('b.wallet_type_id')
                ->groupBy('g.id')
                ->get();

            if($this->checkIsCorrection($start)) { //己關帳 : 差異的部份在今天調整回來
                $this->CorrectionData($records, $this_vendor, $start);
            } else { //未關帳 : 不用調帳可以先刪除後再寫入
                $this->deleteSummaryBetRecordNotCorrection($this_vendor, $start);
                $this->insertData($records, $this_vendor, $start);
            }
        }

        if (in_array($third_party_code, [VendorEnum::CODE_SPADEGAMING, 'ALL'])) {
            //SpadeGaming
            $this_vendor = VendorEnum::SPADEGAMING;
            echo 'SpadeGaming' . PHP_EOL;
            $records = DB::table('spadegaming_bet_records as b')
                ->join('spadegaming_accounts as v', 'b.username', 'v.username')
                ->join('members as m', 'm.id', 'v.member_id')
                ->join('agents as a', 'a.id', 'm.parent')
                ->leftJoin('games as g', function ($join) use ($this_vendor) {
                    $join->on('g.game_type', 'b.game_code')
                        ->where('g.vendor_id', $this_vendor);
                })
                ->whereBetween('b.payout_time', [$start, $end])
                ->where('b.status', 1) //只算已結算的訂單
                ->select(
                    'm.id as member_id',
                    'b.wallet_type_id',
                    'g.id as game_id',
                    DB::raw('SUM(b.bet) as bet'),
                    DB::raw('SUM(b.win_lose) as win_lose'),
                    DB::raw('SUM(b.bonus) as bonus'),
                    'a.percentage',
                    'a.customized_setting_group_id'
                )
                ->groupBy('m.id')
                ->groupBy('b.wallet_type_id')
                ->groupBy('g.id')
                ->get();
            if ($this->checkIsCorrection($start)) { //己關帳 : 差異的部份在今天調整回來
                $this->CorrectionData($records, $this_vendor, $start);
            } else { //未關帳 : 不用調帳可以先刪除後再寫入
                $this->deleteSummaryBetRecordNotCorrection($this_vendor, $start);
                $this->insertData($records, $this_vendor, $start);
            }
        }

        if (in_array($third_party_code, [VendorEnum::CODE_NEXTSPIN, 'ALL'])) {
            //NextSpin
            $this_vendor = VendorEnum::NEXTSPIN;
            echo 'NextSpin' . PHP_EOL;
            $records = DB::table('ns_bet_records as b')
                ->join('ns_accounts as v', 'b.username', 'v.username')
                ->join('members as m', 'm.id', 'v.member_id')
                ->join('agents as a', 'a.id', 'm.parent')
                ->leftJoin('games as g', function ($join) use ($this_vendor) {
                    $join->on('g.game_type', 'b.game_code')
                        ->where('g.vendor_id', $this_vendor);
                })
                ->whereBetween('b.payout_time', [$start, $end])
                ->where('b.status', 1) //只算已結算的訂單
                ->select(
                    'm.id as member_id',
                    'b.wallet_type_id',
                    'g.id as game_id',
                    DB::raw('SUM(b.bet) as bet'),
                    DB::raw('SUM(b.win_lose) as win_lose'),
                    DB::raw('SUM(b.bonus) as bonus'),
                    'a.percentage',
                    'a.customized_setting_group_id'
                )
                ->groupBy('m.id')
                ->groupBy('b.wallet_type_id')
                ->groupBy('g.id')
                ->get();
            if ($this->checkIsCorrection($start)) { //己關帳 : 差異的部份在今天調整回來
                $this->CorrectionData($records, $this_vendor, $start);
            } else { //未關帳 : 不用調帳可以先刪除後再寫入
                $this->deleteSummaryBetRecordNotCorrection($this_vendor, $start);
                $this->insertData($records, $this_vendor, $start);
            }
        }

        if (in_array($third_party_code, [VendorEnum::CODE_PGSSW, 'ALL'])) {
            $vendors = $this->service->getVendorsByThirdPartyCode(VendorEnum::CODE_PGSSW);
            echo 'Evolution' . PHP_EOL;echo 'CODE_PGSSW' . ' count:' . count($vendors) . PHP_EOL;
            if (count($vendors) > 0) {
                foreach ($vendors as $row) {
                    echo $row->name . PHP_EOL;
                    $vendor_id = $row->id;
                    if ($vendor == 0 || $vendor == $vendor_id) {
                        $records = DB::table('pgssw_bet_records as b')
                            ->join('pgssw_accounts as v', 'b.username', 'v.username')
                            ->join('vendor_infos as vi', function ($join) use ($vendor_id) {
                                $join->on('vi.id', 'v.vendor_info_id')
                                    ->where('vi.vendor_id', $vendor_id);
                            })
                            ->join('members as m', 'm.id', 'v.member_id')
                            ->join('agents as a', 'a.id', 'm.parent')
                            ->leftJoin('games as g', function ($join) use ($vendor_id) {
                                $join->on('g.game_type', 'b.game_code')
                                    ->where('g.vendor_id', $vendor_id);
                            })
                            ->whereBetween('b.payout_time', [$start, $end])
                            ->where('b.status', 1) //只算已結算的訂單
                            ->select(
                                'm.id as member_id',
                                'b.wallet_type_id',
                                'g.id as game_id',
                                DB::raw('SUM(b.bet) as bet'),
                                DB::raw('SUM(b.win_lose) as win_lose'),
                                DB::raw('SUM(b.bonus) as bonus'),
                                'a.percentage',
                                'a.customized_setting_group_id'
                            )
                            ->groupBy('m.id')
                            ->groupBy('b.wallet_type_id')
                            ->groupBy('g.id')
                            ->get();
                        if ($this->checkIsCorrection($start)) { //己關帳 : 差異的部份在今天調整回來
                            $this->CorrectionData($records, $vendor_id, $start);
                        } else { //未關帳 : 不用調帳可以先刪除後再寫入
                            $this->deleteSummaryBetRecordNotCorrection($vendor_id, $start);
                            $this->insertData($records, $vendor_id, $start);
                        }
                    }
                }
            }
        }

        if (in_array($third_party_code, [VendorEnum::CODE_PRAGMATICPLAY, 'ALL'])) {
            $this_vendor = VendorEnum::PP;
            echo 'PP' . PHP_EOL;
            $records = DB::table('total_bet_records as r')
                ->join('members as m', 'm.id', 'r.member_id')
                ->join('agents as a', 'a.id', 'm.parent')
                ->where('r.vendor_id', $this_vendor)
                ->where('r.summary_time', $start)
                ->select(
                    'm.id as member_id',
                    'r.wallet_type_id',
                    'r.game_id',
                    DB::raw('SUM(r.bet) as bet'),
                    DB::raw('SUM(r.win_lose) as win_lose'),
                    'a.percentage',
                    'a.customized_setting_group_id'
                )
                ->groupBy('m.id')
                ->groupBy('r.wallet_type_id')
                ->groupBy('r.game_id')
                ->get();
            if($this->checkIsCorrection($start)) { //己關帳 : 差異的部份在今天調整回來
                $this->CorrectionData($records, $this_vendor, $start);
            } else { //未關帳 : 不用調帳可以先刪除後再寫入
                $this->deleteSummaryBetRecordNotCorrection($this_vendor, $start);
                $this->insertData($records, $this_vendor, $start);
            }
        }

        if (in_array($third_party_code, [VendorEnum::CODE_ST8, 'ALL'])) {
            $vendors = $this->service->getVendorsByThirdPartyCode(VendorEnum::CODE_ST8);
            echo 'Evolution' . PHP_EOL;echo 'CODE_ST8' . ' count:' . count($vendors) . PHP_EOL;
            if (count($vendors) > 0) {
                foreach ($vendors as $row) {
                    echo $row->name . PHP_EOL;
                    $vendor_id = $row->id;
                    if ($vendor == 0 || $vendor == $vendor_id) {
                        $records = DB::select("
select m.id as member_id
	, z.wallet_type_id
	, g.id as game_id
	, SUM(z.bet) as bet
	, SUM(z.win - z.bet) as win_lose
	, SUM(z.bonus) as bonus
	, a.percentage
	, a.customized_setting_group_id
from (
	select c.member_id, r.wallet_type_id, r.game_code, r.round_id, sum(b.amount) as bet, sum(r.amount) as win, 0 as bonus
	from st8_bet_records as r
	join st8_bet_records as b 
		ON b.round_id = r.round_id and r.created_at BETWEEN DATE_SUB(?, INTERVAL 1 HOUR) and ? and b.type = 1
	join st8_accounts as c on c.username = r.username
	join vendor_infos as vi on vi.id = c.vendor_info_id AND vi.vendor_id = " . $vendor_id . "
	where r.created_at BETWEEN ? and ? and r.type = 2
	group by c.member_id, r.round_id, r.wallet_type_id, r.game_code
) as z
join members as m on m.id = z.member_id
join agents as a on a.id = m.parent
left join games as g on g.game_type = z.game_code and g.vendor_id = " . $vendor_id . "
group by m.id, z.wallet_type_id, g.id", [$start, $end, $start, $end]);
                        $records = collect($records);
                        if($this->checkIsCorrection($start)) { //己關帳 : 差異的部份在今天調整回來
                            $this->CorrectionData($records, $vendor_id, $start);
                        } else { //未關帳 : 不用調帳可以先刪除後再寫入
                            $this->deleteSummaryBetRecordNotCorrection($vendor_id, $start);
                            $this->insertData($records, $vendor_id, $start);
                        }
                    }
                }
            }
        }
    }

    // $start = 數據時間, $summary_time = 入帳時間
    private function insertData($records, $vendorId, $start, $correction = 0, $summary_time = false)
    {
        if(!$summary_time) {
            $summary_time = $start;
        }

        foreach ($records as $row) {
            //v_jackpot rate
            $vj_rate = isset($this->v_jackpot_rate[$row->customized_setting_group_id])
                ? $this->v_jackpot_rate[$row->customized_setting_group_id]
                : 0.003;

            $data = new SummaryBetRecord();
            $data->summary_time = $summary_time;
            $data->data_time = $start;
            $data->member_id = $row->member_id;
            $data->wallet_type_id = $row->wallet_type_id;
            $data->vendor_id = $vendorId;
            $data->game_id = $row->game_id ? $row->game_id : 0;
            $data->bet = isset($row->bet) ? $row->bet : 0;
            $data->win_lose = $win_lose = isset($row->win_lose) ? $row->win_lose : 0;
            $data->commission = isset($row->commission) ? $row->commission : 0;
            $data->bonus = isset($row->bonus) ? $row->bonus : 0;
            $data->jackpot = isset($row->jackpot) ? $row->jackpot : 0;
            $data->percentage = $row->percentage;
            $data->share_amount = (($row->win_lose * $row->percentage) / 100);
            $data->share_range = '';

            //豪龍不加入v-jackpot
            $data->v_jackpot = (isset($row->bet) && $vendorId != 4 && $vendorId != 36 && !in_array($row->game_id, $this->exclude_games))
                ? floor(strval($row->bet * 10000 * $vj_rate)) / 10000 //轉換比例後保留小數四位
                : 0;
            $data->correction = $correction;

            $data->cost = (isset($this->game_fee_rate[$row->customized_setting_group_id][$vendorId]))
                ? strval($win_lose * $this->game_fee_rate[$row->customized_setting_group_id][$vendorId] * -1)
                : 0;
            $data->save();
        }
    }

    private function CorrectionData($records, $vendorId, $start) {
        echo "***** 調帳作業 *****" . PHP_EOL;

        $originRecords = SummaryBetRecord::where('data_time', $start)
            ->where('vendor_id', $vendorId)
            ->select(
                'member_id',
                'wallet_type_id',
                'vendor_id',
                'game_id',
                DB::raw('SUM(bet) as bet'),
                DB::raw('SUM(win_lose) as win_lose'),
                DB::raw('SUM(bonus) as bonus'),
                DB::raw('SUM(commission) as commission'),
                DB::raw('SUM(jackpot) as jackpot'),
                'percentage'
            )
            ->groupBy('member_id')
            ->groupBy('wallet_type_id')
            ->groupBy('game_id')
            ->get();
        //var_dump($originRecords->toArray());
        $corrections = $records->map(function ($item, $key) use ($originRecords) {
            $origin = $originRecords->where('member_id', $item->member_id)
                ->where('wallet_type_id', $item->wallet_type_id)
                ->where('game_id', $item->game_id)
                ->first();
            $bet = isset($item->bet) ? $item->bet : 0;
            $win_lose = isset($item->win_lose) ? $item->win_lose : 0;
            $commission = isset($item->commission) ? $item->commission : 0;
            $bonus = isset($item->bonus) ? $item->bonus : 0;
            $jackpot = isset($item->jackpot) ? sprintf("%.4f", $item->jackpot) : 0; //只取小數四位
            if ($origin
                && $bet == $origin->bet
                && $win_lose == $origin->win_lose
                && $commission == $origin->commission
                && $bonus == $origin->bonus
                && $jackpot == $origin->jackpot
            ) { // 數據都一樣就不用處理
                return false;
            } elseif (!$origin) { // 查不到數據就新增
                return $item;
            } else { // 查到數據但不同則要處理差異
                $item->bet = $bet - $origin->bet;
                $item->win_lose = $win_lose - $origin->win_lose;
                $item->commission = $commission - $origin->commission;
                $item->bonus = $bonus - $origin->bonus;
                $item->jackpot = $jackpot - $origin->jackpot;
                return $item;
            }
        })->reject(function ($value) {
            return $value === false;
        });

        $corrections_2 = $originRecords->map(function ($item, $key) use($records) {
            $c = $records->where('member_id', $item->member_id)
                ->where('wallet_type_id', $item->wallet_type_id)
                ->where('game_id', $item->game_id)
                ->count();
            if (!$c
                && (
                    abs($item->bet) > 0
                    || abs($item->win_lose) > 0
                    || abs($item->commission) > 0
                    || abs($item->bonus) > 0
                    || abs($item->jackpot) > 0
                )
            ) { //原資料不存在於新運算的結果中, 要減回來
                $item->bet = - $item->bet;
                $item->win_lose = - $item->win_lose;
                $item->commission = - $item->commission;
                $item->bonus = - $item->bonus;
                $item->jackpot = - $item->jackpot;
                return $item;
            }
            return false;
        })->reject(function ($value) {
            return $value === false;
        });
        echo sprintf('corrections : %d / corrections_2 : %d / records : %d', $corrections->count(), $corrections_2->count(), $records->count()) . PHP_EOL;
        // 差異的部份在最後關帳時間入帳
        $utcExpiredTime = $this->getExpiredTime();
        $this->insertData($corrections, $vendorId, $start, 1, $utcExpiredTime);
        $this->insertData($corrections_2, $vendorId, $start, 1, $utcExpiredTime);
    }

    /**
     * @param $records
     * @param $vendorId
     * @param string $date 請填入對應時區的日期
     * @param int $timezone
     */
    private function CorrectionDataOnceOfDate($records, $vendorId, $date, $timezone=8) { // default input GMT+8
        $sTime = date('Y-m-d H:i:s', strtotime($date . sprintf(" %+d hours", -$timezone)));
        $eTime = date('Y-m-d H:i:s', strtotime($sTime . ' +23 hours 59 minutes 59 seconds'));
        echo sprintf("***** 調帳作業 ***** %s, %s", $sTime, $eTime) . PHP_EOL;
        $originRecords = SummaryBetRecord::whereBetween('data_time', [$sTime, $eTime])
            ->where('vendor_id', $vendorId)
            ->select(
                'member_id',
                'wallet_type_id',
                'vendor_id',
                'game_id',
                DB::raw('SUM(bet) as bet'),
                DB::raw('SUM(win_lose) as win_lose'),
                DB::raw('SUM(commission) as commission'),
                DB::raw('SUM(jackpot) as jackpot'),
                'percentage'
            )
            ->groupBy('member_id')
            ->groupBy('wallet_type_id')
            ->groupBy('game_id')
            ->get();
        //var_dump($originRecords->count());
        // 調帳加法
        $corrections = $records->map(function ($item, $key) use ($originRecords) {
            $origin = $originRecords->where('member_id', $item->member_id)
                ->where('wallet_type_id', $item->wallet_type_id)
                ->where('game_id', $item->game_id)
                ->first();
            $bet = isset($item->bet) ? $item->bet : 0;
            $win_lose = isset($item->win_lose) ? $item->win_lose : 0;
            $commission = isset($item->commission) ? $item->commission : 0;
            $jackpot = isset($item->jackpot) ? $item->jackpot : 0;
            if ($origin
                && $bet == $origin->bet
                && $win_lose == $origin->win_lose
                && $commission == $origin->commission
                && $jackpot == $origin->jackpot
            ) { // 數據都一樣就不用處理
                return false;
            } elseif (!$origin) { // 查不到數據就新增
                return $item;
            } else { // 查到數據但不同則要處理差異
                //var_dump($item);
                $item->bet = $bet - $origin->bet;
                $item->win_lose = $win_lose - $origin->win_lose;
                $item->commission = $commission - $origin->commission;
                $item->jackpot = $jackpot - $origin->jackpot;
                return $item;
            }
        })->reject(function ($value) {
            return $value === false;
        });
        // 調帳減法
        $corrections_2 = $originRecords->map(function ($item, $key) use ($records) {
            $c = $records->where('member_id', $item->member_id)
                ->where('wallet_type_id', $item->wallet_type_id)
                ->where('game_id', $item->game_id)
                ->count();
            if (!$c
                && (
                    abs($item->bet) > 0
                    || abs($item->win_lose) > 0
                    || abs($item->commission) > 0
                    || abs($item->jackpot) > 0
                )
            ) { //原資料不存在於新運算的結果中, 要減回來
                $item->bet = - $item->bet;
                $item->win_lose = - $item->win_lose;
                $item->commission = - $item->commission;
                $item->jackpot = - $item->jackpot;
                return $item;
            }
            return false;
        })->reject(function ($value) {
            return $value === false;
        });
        //var_dump($corrections->first());
        echo sprintf('corrections : %d / corrections_2 : %d / records : %d', $corrections->count(), $corrections_2->count(), $records->count()) . PHP_EOL;
        // 差異的部份在最後關帳時間入帳
        $utcExpiredTime = $this->getExpiredTime();
        $this->insertData($corrections, $vendorId, $sTime, 1, $utcExpiredTime);
        $this->insertData($corrections_2, $vendorId, $sTime, 1, $utcExpiredTime);
    }

    // 傳入utc數據時間, 如果己經過了關帳時間就要用調帳的方式
    private function checkIsCorrection($datetime) {
        $utcExpiredTime = $this->getExpiredTime(); // utc 的關帳時間
        //echo $utcExpiredTime;
        if (strtotime($datetime) < strtotime($utcExpiredTime)) {
            return true; //己經關帳需要用調帳的方式
        } else {
            return false; //不用調帳可以直接覆蓋
        }
    }

    // 獲取最後關帳時間
    private function getExpiredTime() {
        $now = time();
        $taipeiExpierdTime = date('Y-m-d 00:00:00', strtotime("-6 hours", $now)); // now +8-14
        $utcExpiredTime = date('Y-m-d H:i:s', strtotime($taipeiExpierdTime . " -8 hours")); // utc 的關帳時間
        return $utcExpiredTime;
    }

    private function deleteSummaryBetRecordNotCorrection($vendor_id, $summary_time) {
        SummaryBetRecord::where('vendor_id', $vendor_id)
            ->where('summary_time', $summary_time)
            ->where('correction', '!=', 1)
            ->delete();
    }
}