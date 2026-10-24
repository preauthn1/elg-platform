<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Enums\Missions\MissionResetTypeEnum;
use App\Models\MemberMission;
use App\Models\Mission;
use App\Models\MissionClaimRecord;
use App\Services\BotNotifyService;
use App\Services\Frontend\Missions\MissionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SummaryMissionDatas extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:summary-mission-datas {--date=} {--hour=}'; // php artisan app:summary-mission-datas --date=2025-03-18 --hour=10

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Summary mission datas';

    private $nowDatetime;

    private $timeOffset;

    /**
     * 本次執行內、彙總 SQL 完全相同的 mission 共用的結果快取。
     *
     * key 的組成規則見 summaryCacheKey()。
     *
     * @var array<string, Collection>
     */
    private array $summaryCache = [];

    public function __construct(
        protected MissionService $missionService,
        protected BotNotifyService $botNotifyService
    ) {
        parent::__construct();

        $this->timeOffset = Carbon::now()->toFrontendTime()->offsetHours;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = $this->option('date') ?
            date('Y-m-d', strtotime($this->option('date'))) :
            date('Y-m-d');

        $hour = $this->option('hour') ?
            (int) $this->option('hour') :
            (int) date('H', strtotime('-10 minutes'));

        $this->nowDatetime = "$date $hour:00:00";

        $this->info('Now datetime: ' . $this->nowDatetime);

        $inProgressMissions = Mission::with('availableAgents:id,hierarchy_path')
            ->where('status', 1)
            ->where('start_time', '<=', $this->nowDatetime)
            ->where('end_time', '>=', $this->nowDatetime)
            ->get();

        foreach ($inProgressMissions as $mission) {
            $this->execMission($mission);
        }
    }

    private function execMission(Mission $mission)
    {
        $summaryStartDate = $this->nowDatetime;
        $summaryEndDate = date('Y-m-d H:00:00', strtotime('+1 hour', strtotime($this->nowDatetime)));

        $lib = $mission->action_type->getSummaryLib();

        $availableAgentHierarchyPaths = $mission->availableAgents->pluck('hierarchy_path')->toArray();

        // For one-time missions, get existing member IDs
        $isOneTimeMission = $mission->action_type->isOneTimeMission();

        $existingMemberIds = [];
        if ($isOneTimeMission) {
            $existingMemberIds = MemberMission::where('mission_id', $mission->id)->pluck('member_id')->toArray();
        }

        try {

            $cacheKey = $this->summaryCacheKey($mission, $availableAgentHierarchyPaths, $existingMemberIds);

            if (isset($this->summaryCache[$cacheKey])) {
                print_r("Reuse cached summary, mission_id: {$mission->id}" . PHP_EOL);

                $this->insertOrUpdateSummary($this->summaryCache[$cacheKey], $mission);

                return;
            }

            $summaryQuery = $lib->exec($mission, $summaryStartDate, $summaryEndDate);

            // For one-time missions, exclude members who already have records
            if ($isOneTimeMission && !empty($existingMemberIds)) {
                $summaryQuery->whereNotIn('member_id', $existingMemberIds);
            }

            if (!empty($availableAgentHierarchyPaths)) {
                $hierarchyPathSubQuery = '';
                foreach ($availableAgentHierarchyPaths as $path) {
                    if ($hierarchyPathSubQuery !== '') {
                        $hierarchyPathSubQuery .= ' OR ';
                    }

                    $path = rtrim($path, '/');
                    $hierarchyPathSubQuery .= "agents.hierarchy_path = '{$path}'";
                    $hierarchyPathSubQuery .= " OR agents.hierarchy_path LIKE '{$path}/%' ";
                }

                $summaryQuery->join('members', 'member_id', '=', 'members.id')
                    ->join('agents', function ($join) use ($hierarchyPathSubQuery) {
                        $join->on('members.parent', '=', 'agents.id')
                            ->whereRaw("($hierarchyPathSubQuery)");
                    });
            }

            $summary = $summaryQuery->get();
            $this->summaryCache[$cacheKey] = $summary;

            $this->insertOrUpdateSummary($summary, $mission);

        } catch (\Exception $e) {
            print_r('Error: ' . $e->getMessage() . PHP_EOL);
            print_r('Mission ID: ' . $mission->id . PHP_EOL);
            print_r('Summary start date: ' . $summaryStartDate . PHP_EOL);
            print_r('Summary end date: ' . $summaryEndDate . PHP_EOL);

            $this->botNotifyService->telegram(
                'Summary mission error: ' . $e->getMessage() . PHP_EOL .
                'Mission ID: ' . $mission->id . PHP_EOL .
                'Summary start date: ' . $summaryStartDate . PHP_EOL .
                'Summary end date: ' . $summaryEndDate . PHP_EOL
            );
        }
    }

    /**
     * 產生彙總結果的快取鍵，一律只納入「會進 SQL」的條件。
     *
     * 時間窗不納入：$this->nowDatetime 在單次執行內固定。
     *
     * `condition_config.value`（達標門檻）必須排除——它只在 insertMissionClaimRecord()
     * 內與 total_progress 比對，不進任何 Lib 的 SQL；階梯式任務（同活動、門檻 20/50/100…）
     * 正是靠排除它才能共用同一份彙總。新增 Lib 時若讓 value 進了 SQL，必須把它加回本鍵，
     * 否則不同門檻的 mission 會共用到錯誤的彙總結果。
     *
     * @param  array<int, string>  $hierarchyPaths
     * @param  array<int, int>  $existingMemberIds
     */
    private function summaryCacheKey(Mission $mission, array $hierarchyPaths, array $existingMemberIds): string
    {
        sort($hierarchyPaths);
        sort($existingMemberIds);

        return md5(json_encode([
            $mission->action_type->value,
            $mission->info['action_config'] ?? [],
            Arr::except($mission->info['condition_config'] ?? [], 'value'),
            $hierarchyPaths,
            $existingMemberIds,
        ]));
    }

    private function insertOrUpdateSummary(Collection $summary, Mission $mission)
    {
        print_r("Insert or update summary, summary_time: {$this->nowDatetime},  mission_id: {$mission->id}" . PHP_EOL);

        if ($summary->isEmpty()) {
            print_r('Summary is empty, skip' . PHP_EOL);

            return;
        }

        $summary->chunk(100)->each(function (Collection $datas, int $idx) use ($mission) {
            print_r("Insert or update summary chunk, idx: $idx, count: " . $datas->count() . PHP_EOL);

            $cols = ['summary_time', 'member_id', 'mission_id', 'progress'];

            $valuesString = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $valuesString = implode(',', array_fill(0, $datas->count(), $valuesString));
            $columnsString = implode(',', $cols);

            $sql = "
                INSERT INTO member_missions ({$columnsString}) 
                VALUES {$valuesString} 
                ON DUPLICATE KEY UPDATE 
                    progress = VALUES(progress)
                ";

            $flattenedValues = [];
            foreach ($datas as $row) {
                $newRow = [$this->nowDatetime, $row->member_id, $mission->id, $row->progress];
                $flattenedValues = array_merge($flattenedValues, $newRow);
            }

            DB::insert($sql, $flattenedValues);

            $this->insertMissionClaimRecord($datas, $mission);
        });
    }

    private function insertMissionClaimRecord(Collection $datas, Mission $mission)
    {
        $memberIdArray = $datas->pluck('member_id')->toArray();
        [$summaryStartDate, $summaryEndDate] = $mission->reset_type->getTimeRange($mission, $this->nowDatetime);
        $memberMissionGroup = MemberMission::where('mission_id', $mission->id)
            ->whereIn('member_id', $memberIdArray)
            ->where(function ($query) use ($summaryStartDate, $summaryEndDate) {
                $query->where('summary_time', '>=', $summaryStartDate)
                    ->where('summary_time', '<', $summaryEndDate);
            })
            ->select('mission_id', 'member_id')
            ->selectRaw('MAX(summary_time) AS summary_time')
            ->selectRaw('SUM(progress) AS total_progress')
            ->selectRaw('DATE_FORMAT(DATE_ADD(MAX(summary_time), INTERVAL ? HOUR), ?) AS date', [$this->timeOffset, '%Y-%m-%d'])
            ->selectRaw('DATE_FORMAT(DATE_ADD(MAX(summary_time), INTERVAL ? HOUR), ?) AS week', [$this->timeOffset, '%x-%v'])
            ->selectRaw('DATE_FORMAT(DATE_ADD(MAX(summary_time), INTERVAL ? HOUR), ?) AS month', [$this->timeOffset, '%Y-%m'])
            ->groupBy('member_id')
            ->get();

        foreach ($datas as $data) {
            $memberMission = $memberMissionGroup->where('member_id', $data->member_id)->first();
            if ($memberMission !== null && $memberMission->total_progress >= $mission->condition_value) {
                $missionClaimRecord = MissionClaimRecord::where('mission_id', $mission->id)->where('member_id', $data->member_id)
                    ->when($mission->reset_type->value == MissionResetTypeEnum::DAILY->value, function ($query) use ($memberMission) {
                        $query->where('date', $memberMission->date);
                    })
                    ->when($mission->reset_type->value == MissionResetTypeEnum::WEEKLY->value, function ($query) use ($memberMission) {
                        $query->where('week', $memberMission->week);
                    })
                    ->when($mission->reset_type->value == MissionResetTypeEnum::MONTHLY->value, function ($query) use ($memberMission) {
                        $query->where('month', $memberMission->month);
                    })
                    ->first();

                if ($missionClaimRecord === null) {
                    MissionClaimRecord::create([
                        'mission_id' => $mission->id,
                        'member_id' => $data->member_id,
                        'reward_type' => $mission->info['reward_config']['type'],
                        'reward_value' => $mission->info['reward_config']['value'],
                        'date' => $memberMission->date,
                        'week' => $memberMission->week,
                        'month' => $memberMission->month,
                        'finish_time' => Carbon::now(),
                    ]);
                }
            }
        }
    }
}
