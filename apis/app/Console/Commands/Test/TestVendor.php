<?php

declare(strict_types=1);

namespace App\Console\Commands\Test;

use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Helpers\Common;
use App\Models\AgentVendor;
use App\Models\Game;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Services\MemberTransferService;
use App\Services\VendorService;
use App\Services\WalletService;
use Exception;
use Illuminate\Support\Facades\App;

/**
 * 測試代理的遊戲商戶配置能否順利完成以下操作
 * 1. 建立遊戲帳號
 * 2. 餘額轉入遊戲
 * 3. 查詢遊戲餘額
 * 4. 取得遊戲網址
 * 5. 餘額轉回本地
 * 6. 查詢遊戲餘額
 *
 * php artisan test:vendor
 */
class TestVendor extends TestVendorCommand
{
    protected $signature = 'test:vendor';

    protected $description = '測試遊戲配置能否正常運作';

    private array $agentOptions = [
        '4249 - TPower (frank)',
        '59562 - VWorld (devmy)',
        '100074 - VN (devVietwin88)',
    ];

    public function handle(): void
    {
        try {
            $this->steps();
        } catch (Exception $e) {
            $this->error('錯誤: ' . $e->getMessage());

            return;
        }
    }

    public function steps(): void
    {
        // Service
        $vendorService = App::make(VendorService::class);
        $memberTransferService = App::make(MemberTransferService::class);
        $walletService = App::make(WalletService::class);

        $selectAgent = $this->choice('請選擇站點對應的測試代理', $this->agentOptions, 0);
        $selectAgent = explode(' - ', $selectAgent);
        $agentId = (int) $selectAgent[0];

        $vendorId = (int) $this->ask('請輸入遊戲商ID');

        $memberId = (int) $this->ask('請輸入會員ID', '49179');
        $member = Member::find($memberId);

        if (!$member) {
            throw new Exception('會員不存在');
        }

        // 檢查代理商是否存在
        $agent = $this->checkAgent($agentId);
        // 檢查遊戲商是否存在
        $vendor = $this->checkVendor($vendorId);
        // 檢查代理與遊戲商關聯
        $agentVendor = $this->getAgentVendor($agentId, $vendorId);
        // 檢查代理的遊戲商配置
        $vendorInfo = $this->getVendorInfo($agentVendor->vendor_info_id);
        $vendorDriver = $vendorService->getDriver($vendorId, $member);

        // 創建遊戲帳號
        try {
            /** @phpstan-ignore arguments.count */
            if ($vendorDriver->createAccount()) {
                $this->info('遊戲帳號創建成功');
            } else {
                throw new Exception('遊戲帳號創建失敗');
            }
        } catch (Exception $e) {
            // throw new Exception('遊戲帳號創建失敗:' . $e->getMessage());
            $this->error('遊戲帳號創建失敗，請確認 Driver 有實作 createAccount()');
        }

        // 綁定遊戲帳
        $vendorAccount = $vendorDriver->getVendorAccount();
        if ($vendorAccount) {
            $this->info('綁定遊戲帳號成功');
        } else {
            throw new Exception('綁定遊戲帳號失敗');
        }

        // 確保 member 有 100 元餘額
        if ($member->balance < 100) {
            $member->update(['balance' => 100]);
            $member->refresh();
        }

        // 餘額轉入遊戲
        $result = $memberTransferService->transferToVendor($vendorDriver, '100');
        // vendor_balance 現為遊戲商幣別，需與 member->balance × rate 比較
        $expectedVendorBalance = Common::numMul((string) $member->balance, $vendorInfo->rate);
        if (is_array($result) && isset($result['vendor_balance']) && Common::numCompare($result['vendor_balance'], $expectedVendorBalance) >= 0) {
            $this->info('餘額轉入遊戲成功, 轉入: ' . $member->balance);
        } else {
            throw new Exception('餘額轉入遊戲失敗');
        }

        // 查詢遊戲餘額
        $rateBalance = Common::numMul($member->balance, $vendorInfo->rate, 4);
        $vendorBalance = $vendorDriver->getBalance();
        if ($vendorBalance == $rateBalance) {
            $this->info('查詢遊戲餘額成功, 餘額: ' . $vendorBalance);
        } else {
            throw new Exception('查詢遊戲餘額失敗, 餘額: ' . $vendorBalance . '!=' . $rateBalance);
        }

        // 餘額轉回本地
        $result = $memberTransferService->transferToLocal($vendorDriver, '100');
        if (is_array($result) && isset($result['vendor_balance']) && (int) $result['vendor_balance'] == '0') {
            $this->info('餘額轉回本地成功');
        } else {
            throw new Exception('餘額轉回本地失敗');
        }

        // 查詢遊戲餘額
        $vendorBalance = $vendorDriver->getBalance();
        if ($vendorBalance == '0') {
            $this->info('查詢遊戲餘額成功, 餘額: ' . $vendorBalance);
        } else {
            throw new Exception('查詢遊戲餘額失敗, 餘額: ' . $vendorBalance);
        }

        // 取得遊戲網址
        $params = $this->getGameParams($agent->hierarchy_array, $vendorId); // VendorGameUrlParamsData
        $gameUrlResult = $vendorDriver->getGameUrl($params);
        if ($gameUrlResult instanceof VendorGameUrlResultData) {
            $this->info('取得遊戲網址成功: ' . $gameUrlResult->pc);
        } else {
            throw new Exception('取得遊戲網址失敗');
        }

        $this->info('所有步驟完成，測試成功！');
    }

    protected function getAgentVendor($agentId, $vendorId): AgentVendor
    {
        $agentVendor = AgentVendor::query()
            ->where('agent_id', $agentId)
            ->where('vendor_id', $vendorId)
            ->where('vendor_info_id', '!=', 0)
            ->where('status', 1)
            ->first();

        if (!$agentVendor) {
            throw new Exception('代理商與遊戲商關聯不存在');
        }

        return $agentVendor;
    }

    protected function getVendorInfo($vendorInfoId): VendorInfo
    {
        $vendorInfo = VendorInfo::query()
            ->where('id', $vendorInfoId)
            ->where('type', 0)
            ->where('status', 1)
            ->first();

        if (!$vendorInfo) {
            throw new Exception('遊戲商資訊不存在或未啟用');
        }

        return $vendorInfo;
    }

    /**
     * 取得 vendor 第一筆有效的遊戲配置
     */
    protected function getGameParams($agentIds, $vendorId): VendorGameUrlParamsData
    {
        $game = Game::query()
            ->where('vendor_id', $vendorId)
            ->where('status', 1)
            ->whereHas('agentGames', function ($query) use ($agentIds) {
                $query->whereIn('agent_id', $agentIds);
                $query->where('status', 1);
            })
            ->first();

        if (!$game) {
            throw new Exception('遊戲不存在或未啟用');
        }

        return VendorGameUrlParamsData::from([
            'gameCode' => $game->game_type,
            'deviceType' => DeviceTypeEnum::MOBILE,
            'language' => LanguageEnum::from(3), // 簡體中文
            'exitUrl' => '',
            'ip' => '0.0.0.0',
            'remark' => '',
            'info' => $game->info ?? null,
        ]);
    }
}
