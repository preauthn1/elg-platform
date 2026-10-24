<?php

declare(strict_types=1);

namespace App\Console\Commands\SMS;

use App\Models\Agent;
use App\Models\Country;
use App\Models\SmsGateway;
use App\Models\SmsGatewayCountry;
use App\Models\SmsGatewayInfo;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * php artisan agent:add-sms-gateway-info
 *
 * 關聯的資料表
 * agent: 代理, 透過 sms_gateway_group_id 來關聯 sms_gateway_group_details
 * sms_gateway_group_details: 代理簡訊組別
 * sms_gateways: 三方簡訊商
 * sms_gateway_infos: 代理簡訊組別關聯的三方簡訊商商戶, 透過 sms_gateway_group_id 關聯 sms_gateway_group_details
 * sms_gateway_info_countries: 三方簡訊商商戶可用的國家
 * countries: 電話國碼, 透過 sms_gateway_info_countries 關聯
 */
class AddSmsGatewayInfo extends Command
{
    protected $signature = 'agent:add-sms-gateway-info {--t|test : 測試模式，所有操作不會寫入資料庫}';

    protected $description = '新增代理可用的簡訊配置';

    protected int $vendorId;

    protected int $agentId;

    // protected array $agentOptions = [
    //     '4249 - TP (TPower)',
    //     '59562 - VW (VWorld)',
    //     '100074 - VN (Win88)',
    // ];

    /**
     * Execute the console command.
     *
     * @throws Throwable
     */
    public function handle(): void
    {
        $isTest = $this->option('test');

        try {
            DB::beginTransaction();

            $this->process();

            if ($isTest) {
                DB::rollBack();
                $this->info('腳本已執行完成，所有資料已回滾，未寫入資料庫。');
            } else {
                DB::commit();
                $this->info('腳本已執行完成');
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return;
        }
    }

    public function process(): void
    {
        $agentId = (int) $this->ask('請輸入代理商ID');

        // 代理使用的簡訊群組ID
        try {
            $agent = Agent::findOrFail($agentId);
            $smsGatewayGroupId = $agent->sms_gateway_group_id;
        } catch (ModelNotFoundException $e) {
            $this->error('代理不存在或未找到。');

            return;
        }

        if (!$smsGatewayGroupId) {
            $this->error('代理未設定 sms_gateway_group_id。');

            return;
        }

        // 查詢目前可用的簡訊商, 作為選項
        $smsGateways = SmsGateway::query()
            ->pluck('name', 'id')
            ->toArray();
        $smsGatewayName = $this->choice('請選擇要新增的簡訊商', $smsGateways, 0);
        $smsGatewayId = array_search($smsGatewayName, $smsGateways, true);
        try {
            $smsGateway = SmsGateway::findOrFail($smsGatewayId);
            $info = json_decode($smsGateway->info, true);
            $defaultInfo = [];
            foreach ($info as $key => $item) {
                if (is_array($item)) {
                    $defaultInfo[$key] = $item['type'] == 'text' ? '' : 1;
                }
            }
        } catch (ModelNotFoundException $e) {
            $this->error('簡訊商不存在或未找到。');

            return;
        }

        // 查詢目前可用的國家, 作為選項
        $countries = Country::query()
            ->pluck('name', 'id')
            ->toArray();

        // 多選國家（返回陣列）
        $selectedCountryNames = $this->choice('請選擇適用的國家 (多選請用,分隔)', array_values($countries), 0, null, true);

        // 確保 $selectedCountryNames 是陣列
        if (!is_array($selectedCountryNames)) {
            $selectedCountryNames = [$selectedCountryNames];
        }

        // 將國家名稱轉換為 ID 陣列
        $selectedCountryIds = [];
        foreach ($selectedCountryNames as $countryName) {
            $countryId = array_search($countryName, $countries, true);
            if ($countryId !== false) {
                $selectedCountryIds[] = $countryId;
            }
        }

        if (empty($selectedCountryIds)) {
            $this->error('未選擇任何有效的國家。');

            return;
        }

        $this->info('已選擇的國家：' . implode(', ', $selectedCountryNames));

        try {
            DB::beginTransaction();
            /** @var SmsGatewayInfo $smsGatewayInfo */
            $smsGatewayInfo = $agent->smsGatewayInfos()->updateOrCreate([
                'sms_gateway_group_id' => $smsGatewayGroupId,
                'sms_gateway_id' => $smsGatewayId,
            ], [
                'info' => $defaultInfo,
                'sort' => 0,
                'cost' => 0,
                'status' => 0, // 配置好 info 再啟用
                'admin' => 0,
            ]);

            // 批次建立多個國家關聯
            foreach ($selectedCountryIds as $countryId) {
                $smsGatewayInfo->smsGatewayInfoCountries()->updateOrCreate([
                    'sms_gateway_info_id' => $smsGatewayInfo->id,
                    'country_id' => $countryId,
                ], [
                    'status' => 1,
                    'admin' => 0,
                ]);
                // 比對條件只放 sms_gateway_id + country_id：status／admin 一併比對時，
                // 既有列（admin 為建立者 ID）永遠對不上，會補插一筆 admin=0 的重複列。
                SmsGatewayCountry::query()->firstOrCreate([
                    'sms_gateway_id' => $smsGatewayId,
                    'country_id' => $countryId,
                ], [
                    'status' => 1,
                    'admin' => 0,
                ]);
            }

            DB::commit();
            $this->info('已為代理新增簡訊配置，共關聯 ' . count($selectedCountryIds) . ' 個國家。');
        } catch (Exception $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return;
        }
    }
}
