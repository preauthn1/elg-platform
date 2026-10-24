<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Frontend\NewbieChallenge\NewbieChallengeDetailResource;
use App\Http\Resources\Frontend\NewbieChallenge\NewbieChallengeProgressResource;
use App\Models\Vendor;
use App\Services\NewbieChallengeService;
use Dedoc\Scramble\Attributes\Group;

#[Group('NewbieChallenge (新人專享挑戰活動)')]
class NewbieChallengeController extends Controller
{
    /** languages.id，找不到對應語系內容時 fallback 這個 */
    private const int DEFAULT_LANGUAGE_ID = 1;

    public function __construct(
        private NewbieChallengeService $newbieChallengeService
    ) {}

    /**
     * 參加頁的活動介紹內容（依語系，無對應語系 fallback 英文；不含會員參加狀態）
     */
    public function detail()
    {
        $userData = $this->getUserContext();
        $languageId = $userData->languageId;

        $config = $this->newbieChallengeService->getDetail($userData->member->parent);

        $lang = $config->configLangs->firstWhere('language_id', $languageId)
            ?? $config->configLangs->firstWhere('language_id', self::DEFAULT_LANGUAGE_ID);

        $config->name = $lang?->name;
        $config->content = $lang?->content;

        // 遊戲商顯示名稱優先吃該代理群組的客製化名稱（vendor_customized_items.name），沒設定才
        // 退回 vendors.name，跟大廳/交易紀錄頁（TransactionLogQueryService）同一套代理客製化邏輯
        $agentGroupId = $userData->agent->vendor_customize_group_id;
        $allowedVendorIds = $this->newbieChallengeService->resolveAllowedVendorIds($config);
        $config->allowedVendors = Vendor::whereIn('id', $allowedVendorIds)
            ->with(['vendorCustomizedItems' => fn ($query) => $query
                ->where('agent_group_id', $agentGroupId)])
            ->get(['id', 'name'])
            ->each(function (Vendor $vendor) {
                $customName = $vendor->vendorCustomizedItems->first()?->name;
                $vendor->name = filled($customName) ? $customName : $vendor->name;
                $vendor->unsetRelation('vendorCustomizedItems');
            });

        return ApiResponse::success(NewbieChallengeDetailResource::make($config));
    }

    /**
     * 領取活動金
     */
    public function join()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        // 不在這裡包 DB::transaction()：join() 內的 checkDuplicateBlock() 阻擋時要寫入
        // newbie_challenge_block_joins/records 並直接 throw，這筆紀錄要能保留下來給後台看，
        // 不能被包住整個 join() 的外層 transaction 一起 rollback 掉；真正需要原子性的
        // createProgress() 內部已經自己包了一層 DB::transaction()，這裡不用也不該重複包
        $this->newbieChallengeService->join($member);

        return ApiResponse::success();
    }

    /**
     * 查詢活動進度
     */
    public function progress()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $progress = $this->newbieChallengeService->getProgress($member);

        return ApiResponse::success(NewbieChallengeProgressResource::make($progress));
    }

    /**
     * 挑戰流水達標後，按按鈕結算可解鎖彩金並進入下一階段
     */
    public function unlockBonus()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $progress = $this->newbieChallengeService->unlockBonus($member);

        return ApiResponse::success(NewbieChallengeProgressResource::make($progress));
    }

    /**
     * 待領取彩金階段，會員在倒數頁按「下一步」主動確認已儲值：查首筆符合資格的儲值來核銷彩金並進入
     * 彩金任務中；查無符合資格的儲值則明確回錯誤。只適用 BONUS_PENDING，其他階段呼叫回錯誤
     */
    public function confirmDeposit()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $progress = $this->newbieChallengeService->confirmDeposit($member);

        return ApiResponse::success(NewbieChallengeProgressResource::make($progress));
    }

    /**
     * 彩金流水達標後，按按鈕把彩金撥入真金錢包並進入下一階段
     */
    public function claimBonus()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $progress = $this->newbieChallengeService->claimBonus($member);

        return ApiResponse::success(NewbieChallengeProgressResource::make($progress));
    }

    /**
     * 主動放棄活動
     */
    public function quit()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $progress = $this->newbieChallengeService->quit($member);

        return ApiResponse::success(NewbieChallengeProgressResource::make($progress));
    }
}
