<?php

declare(strict_types=1);

namespace App\Console\Commands\SMS;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\SmsGateway;
use App\Models\SmsGatewayInfo;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan test:sms-gateway
 */
class TestSmsGateway extends Command
{
    protected $signature = 'test:sms-gateway';

    protected $description = '測試簡訊商發送SMS';

    public function handle(): void
    {
        $smsGatewayInfo = $this->getSmsGatewayInfo();
        if (!$smsGatewayInfo) {
            return;
        }

        if (!$this->validateCountries($smsGatewayInfo)) {
            return;
        }

        $mobile = $this->ask('請輸入手機號', '60164580966');

        // 某些簡訊商（如 FgGateway）需要 member 物件來獲取 agent 配置
        // 如果不需要可直接按 Enter 跳過
        $memberId = $this->ask('請輸入會員ID（可選，按 Enter 跳過）', '');

        $this->processSmsDelivery($smsGatewayInfo, $mobile, $memberId);
    }

    /**
     * 取得並驗證簡訊商資訊
     */
    private function getSmsGatewayInfo(): ?SmsGatewayInfo
    {
        // 查詢啟用中的簡訊商
        $smsGateways = SmsGateway::where('status', 1)
            ->orderBy('id')
            ->get();

        if ($smsGateways->isEmpty()) {
            $this->error('沒有啟用中的簡訊商');

            return null;
        }

        // 建立選單選項
        $choices = $smsGateways->mapWithKeys(function ($gateway) {
            return [$gateway->id => sprintf('[%d] %s (%s)', $gateway->id, $gateway->name, $gateway->code)];
        })->toArray();

        // 顯示選單
        $this->info('可用的簡訊商：');
        foreach ($choices as $choice) {
            $this->line($choice);
        }

        $smsGatewayId = $this->ask('請選擇簡訊商 ID');

        // 驗證選擇的 ID 是否有效
        $selectedGateway = $smsGateways->firstWhere('id', $smsGatewayId);
        if (!$selectedGateway) {
            $this->error('無效的簡訊商 ID');

            return null;
        }

        $this->info(sprintf('已選擇: %s (%s)', $selectedGateway->name, $selectedGateway->code));

        // 查詢該簡訊商的配置
        $smsGatewayInfos = SmsGatewayInfo::where('sms_gateway_id', $smsGatewayId)
            ->where('status', 1)
            ->get();

        if ($smsGatewayInfos->isEmpty()) {
            $this->error('該簡訊商沒有啟用的配置');

            return null;
        }

        // 如果有多個配置，讓用戶選擇
        if ($smsGatewayInfos->count() > 1) {
            $infoChoices = $smsGatewayInfos->mapWithKeys(function ($info) {
                return [$info->id => sprintf("[%d] Sort: %d, Cost: %s \n %s", $info->id, $info->sort, $info->cost, json_encode($info->info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];
            })->toArray();

            $this->info('該簡訊商有多個配置：');
            foreach ($infoChoices as $choice) {
                $this->line($choice);
            }

            $smsGatewayInfoId = $this->ask('請選擇配置 ID');
            $smsGatewayInfo = $smsGatewayInfos->firstWhere('id', $smsGatewayInfoId);

            if (!$smsGatewayInfo) {
                $this->error('無效的配置 ID');

                return null;
            }
        } else {
            $smsGatewayInfo = $smsGatewayInfos->first();
            $this->info(sprintf('使用配置 ID: %d', $smsGatewayInfo->id));
        }

        return $smsGatewayInfo;
    }

    /**
     * 驗證簡訊商國家設定並顯示資訊
     */
    private function validateCountries(SmsGatewayInfo $smsGatewayInfo): bool
    {
        $countries = $smsGatewayInfo->countries;

        if ($countries->isEmpty()) {
            $this->error('簡訊商沒有設定國家');

            return false;
        }

        foreach ($countries as $country) {
            $this->line(sprintf('簡訊商適用國家: %s (%s)', $country->name, $country->country_code));
        }

        return true;
    }

    /**
     * 處理簡訊發送並顯示結果
     */
    private function processSmsDelivery(SmsGatewayInfo $smsGatewayInfo, string $mobile, string $memberId): void
    {
        $result = $this->sendSms($smsGatewayInfo, $mobile, $memberId);

        if (!$result instanceof SmsResultData) {
            $this->error('簡訊發送失敗');

            return;
        }

        if ($result->status === 1) {
            $this->info('簡訊發送成功');
            $this->info(sprintf('回傳結果: %s', $result->response));
            $this->info(sprintf('交易 ID: %s', $result->transactionId));
        } else {
            $this->error('簡訊發送失敗');
            $this->error(sprintf('回傳結果: %s', $result->response));
        }
    }

    /**
     * 執行簡訊發送
     */
    private function sendSms(SmsGatewayInfo $smsGatewayInfo, string $mobile, string $memberId): ?SmsResultData
    {
        $gatewayClass = sprintf('App\Services\SmsGateways\Suppliers\%sGateway', ucfirst(strtolower($smsGatewayInfo->code)));

        if (!class_exists($gatewayClass)) {
            $this->error('簡訊商類別不存在');

            return null;
        }

        try {
            // 如果提供 memberId 則查詢，否則創建臨時 Member 物件
            if (Common::isNotEmptyString($memberId)) {
                $member = Member::findOrFail($memberId);
                $this->info(sprintf('使用會員: %s (ID: %d)', $member->account, $member->id));
            } else {
                // 創建臨時 Member 物件（不保存到資料庫）
                $member = new Member;
                $member->id = 0;
                $member->account = 'test_account';
                $this->warn('未提供會員 ID，使用臨時測試物件（某些簡訊商可能會失敗）');
            }

            $gateway = new $gatewayClass;

            $sendData = SmsSendData::from([
                'smsGatewayInfo' => $smsGatewayInfo,
                'mobile' => $mobile,
                'content' => sprintf('OTP Verify Code: %06d', random_int(0, 999999)),
                'member' => $member,
            ]);

            return $gateway->send($sendData);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return null;
        }
    }
}
