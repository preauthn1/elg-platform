<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Enums\EventV2\EventV2RecycleStatusEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Enums\Vendor\VendorInfoTypeEnum;
use App\Models\EventV2Recycle;
use App\Models\EventV2UserJoin;
use App\Models\Param;
use App\Models\VendorInfo;
use App\Services\VendorService;
use App\Vendors\AbstractTransferWallet;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecycleEventV2Schedule extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:recycle-event-v2-schedule';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    private array $modelInstances = [];

    private array $recycleVendors = [
        // 因為對帳關係，wbet拉帳payout_time會抓working_date，會導致payout_time有可能比end_time還早
        VendorIdEnum::WBET->value,
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $param = Param::where('type', 'event_v2')->where('parameter', 'recycle_days')->first();
        $days = $param->value ?? 7;

        $now = Carbon::now();
        // $now = Carbon::parse('2025-12-18 17:00:00');
        $start = $now->copy()->toFrontendTime()->subDays($days)->startOfDay()->toSystemTime();
        $end = $now->copy()->toFrontendTime()->subDays($days)->endOfDay()->toSystemTime();

        $userJoin = EventV2UserJoin::select([
            'event_v2_user_joins.id',
            'event_v2_user_joins.end_time',
            'event_v2_accounts.account_id',
            'vendors.id as vendor_id',
            'vendors.tname',
        ])
            ->join('event_v2_accounts', 'event_v2_accounts.event_v2_user_join_id', '=', 'event_v2_user_joins.id')
            ->join('vendors', function ($join) {
                $join->on('event_v2_accounts.vendor_id', '=', 'vendors.id')
                    ->where('vendors.is_single_wallet', 0);
            })
            ->whereIn('event_v2_user_joins.status', [
                EventV2UserJoinStatusEnum::COMPLETE->value,
                EventV2UserJoinStatusEnum::QUIT->value,
                EventV2UserJoinStatusEnum::SYSTEM_INIT->value,
            ])
            ->whereBetween('event_v2_user_joins.created_at', [$start, $end])
            ->get();

        $this->info('Recycle count ' . $userJoin->count());
        Log::info('Recycle count ' . $userJoin->count());

        $vendorService = app(VendorService::class);
        foreach ($userJoin as $join) {
            $vendorDriver = $vendorService->getDriver($join->vendor_id);

            try {
                $checkRecycle = $this->_checkRecycle($vendorDriver, $join);

                if ($checkRecycle === true) {

                    $balance = $vendorDriver->getBalance();

                    if ((float) $balance > 0) {
                        // 新增回收紀錄
                        EventV2Recycle::create([
                            'event_v2_user_join_id' => $join->id,
                            'final_balance' => 0,
                            'withdraw' => 0,
                            'recycle_amount' => $balance,
                            'status' => EventV2RecycleStatusEnum::SYSTEM_SCHEDULE->value,
                        ]);

                        $this->info('event v2 recycle balance ' . $balance . ', user join id: ' . $join->id . ', vendor id: ' . $join->vendor_id . ', account id: ' . $join->account_id . ', tname: ' . $join->tname);
                        Log::info('event v2 recycle balance ' . $balance . ', user join id: ' . $join->id . ', vendor id: ' . $join->vendor_id . ', account id: ' . $join->account_id . ', tname: ' . $join->tname);
                    }
                }
            } catch (\Exception $e) {
                $this->error('Error processing event v2 recycle: ' . $e->getMessage() . ' for user join id: ' . $join->id);
                Log::error('Error processing event v2 recycle: ' . $e->getMessage() . ' for user join id: ' . $join->id);
            }

        }
    }

    private function _getInstance(string $class): object
    {
        if (!isset($this->modelInstances[$class])) {
            $this->modelInstances[$class] = new $class;
        }

        return $this->modelInstances[$class];
    }

    private function _checkRecycle(AbstractTransferWallet &$vendorDriver, EventV2UserJoin $join)
    {
        $accountModel = $this->_getInstance($vendorDriver->getAccountModel());
        // 取出跟bet_record表對應的username欄位
        $accountVendorUsernameColumn = $accountModel->getVendorUsernameColumn();
        $account = $accountModel::find($join->account_id);
        $vendorDriver->setVendorAccount($account);

        $vendorInfo = VendorInfo::where('vendor_id', $join->vendor_id)->where('type', VendorInfoTypeEnum::EVENT_V2->value)->first();
        $vendorDriver->setVendorInfo($vendorInfo);

        $betRecordModel = $this->_getInstance($vendorDriver->getBetRecordModel());
        $payoutTimeColumn = $betRecordModel->getPayoutTimeColumn();
        $betTimeColumn = $betRecordModel->getBetTimeColumn();
        // 取出跟account表對應的username欄位
        $betRecordVendorUsernameColumn = $betRecordModel->getVendorUsernameColumn();

        // 特殊遊戲商直接減查餘額並回收
        if (in_array($join->vendor_id, $this->recycleVendors) === true) {
            return true;
        }

        $filterColumn = $payoutTimeColumn ?? $betTimeColumn;
        // 取出bet_record派彩時間大於參加活動結束時間
        $betRecord = $betRecordModel::where($betRecordVendorUsernameColumn, $account->{$accountVendorUsernameColumn})
            ->where($filterColumn, '>=', $join->end_time)
            ->first();

        if ($betRecord !== null) {
            return true;
        }

        return false;
    }
}
