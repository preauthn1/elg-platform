<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets\Concerns;

use App\Exceptions\MemberNotFoundException;
use App\Exceptions\SessionTokenNotFoundException;
use App\Exceptions\VendorAccountException;
use App\Exceptions\VendorNotFoundException;
use App\Http\Controllers\External\SingleWallets\Contracts\AllowsUnboundAccount;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldResolveVendorAccount;
use App\Http\Controllers\External\SingleWallets\Data\AccountIdentifier;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Vendors\Models\AbstractAccount;
use Illuminate\Http\Request;
use LogicException;

/**
 * 回調共通層：由三方帳號解析出 {vendor}_account、member 與 vendor_info。
 *
 * 🔴 **不是單錢包專屬**：轉帳錢包的 TransferWallets\NextSpinController 也靠 getMemberByThirdPartyUsername()
 * 與 getVendorInfo() 驗會員與商戶。改簽名或收窄可見度前要連 TransferWallets 一起看。
 *
 * 兩種進入方式：
 * - 子類在自己的 callAction 內直接呼叫 {@see self::setUpVendorAccount()}（既有遊戲商的寫法，舊版副本、待刪）。
 * - 子類掛 {@see ShouldResolveVendorAccount}，
 *   由 BaseController::callAction() 呼叫 {@see self::resolveAccountAndVendorInfo()}；它串 {@see self::resolveAccount()}
 *   與 {@see self::resolveVendorInfo()}，後者順帶驗商戶歸屬；未綁定放行則依 {@see AllowsUnboundAccount} 決定。
 *
 * 🔴 **這一層只解析、不驗啟用狀態**：帳號 status 與商戶 status 都不在這裡擋。停用後仍要能結算、取消、
 *   查餘額，否則已扣的注金永遠結不了；「現在能不能下注」由 BaseController::verifyBettability() →
 *   BetHelper::assertMemberAbleToBet() 在扣款類 action 內驗。
 *
 * 兩個副本刻意並存：舊版語意有出入但 30 多支 controller 在用，逐家遷移到 marker 流程後才刪舊版。
 *
 * @property int $vendorId
 * @property ?AbstractAccount $account
 * @property ?VendorInfo $vendorInfo
 * @property ?Member $member
 * @property string $accountClass
 * @property string $vendorInfoOperatorField
 */
trait ResolvesAccountAndVendorInfo
{
    /**
     * BaseController::callAction() 的帳號解析步驟：先 {@see self::resolveAccount()} 解析帳號與會員，
     * 再 {@see self::resolveVendorInfo()} 解析商戶，各自把取出來的東西驗完。
     * 子類掛 {@see AllowsUnboundAccount} 且 $method 在其清單內時，允許 member_id <= 0 的池帳號通過，
     * 此時 $this->member 為 null。
     *
     * 這一支連同兩個 resolve 方法是 {@see self::setUpVendorAccount()} 的新版副本，給走 marker 流程的
     * controller 用。與舊版的語意差異：
     * - 識別子可以是 username 或 session_token，由子類的 accountIdentifier() 決定（舊版只吃 username，
     *   用 token 的要另呼叫 getMemberBySessionToken()）；token 查無拋 SessionTokenNotFoundException
     * - 查無帳號與「帳號存在但沒綁會員」分成兩個訊息（舊版都叫 User not found）
     * - 帳號指到的會員不存在時訊息為 Member not found（舊版叫 User Disabled，但那不是停用）
     * - $allowUnbound 為 true 只放行 member_id <= 0 的池帳號；member_id > 0 卻查不到會員仍是資料錯誤，一樣拋
     * - 多驗商戶歸屬一項，舊版不驗
     * - 不再接受額外的 where 條件
     * 例外型別與舊版相同，子類既有的 catch 分支不必改。
     *
     * @throws VendorAccountException|MemberNotFoundException|VendorNotFoundException
     */
    protected function resolveAccountAndVendorInfo(Request $request, string $method): void
    {
        if (!$this instanceof ShouldResolveVendorAccount) {
            throw new LogicException(static::class . ' must implement ' . ShouldResolveVendorAccount::class);
        }

        $identifier = $this->accountIdentifier($request, $method);
        if ($identifier === null) {
            return;
        }

        if ($identifier->value === '') {
            throw new VendorAccountException(
                $identifier->isSessionToken() ? 'Session token is required' : 'Username is required'
            );
        }

        $allowUnbound = $this instanceof AllowsUnboundAccount
            && in_array($method, $this->methodsAllowingUnboundAccount(), true);

        $this->resolveAccount($identifier, $allowUnbound);
        $this->resolveVendorInfo($this->account);
    }

    /**
     * 依識別子（username 或 session_token）解析出 {vendor}_account 與 member 並塞進成員變數。
     * 只管帳號與會員，不碰商戶；不驗帳號 status（理由見 trait 說明）。
     *
     * @throws VendorAccountException|MemberNotFoundException
     */
    protected function resolveAccount(AccountIdentifier $identifier, bool $allowUnbound): void
    {
        $account = $this->accountClass::query()->where($identifier->column, $identifier->value)->first();

        if (!$account instanceof AbstractAccount) {
            throw $identifier->isSessionToken()
                ? new SessionTokenNotFoundException('Session token not found')
                : new VendorAccountException('Account not found');
        }

        if ($account->member_id <= 0) {
            if (!$allowUnbound) {
                throw new VendorAccountException('Account not bound to any member');
            }

            $member = null;
        } else {
            $member = Member::find($account->member_id);

            if (!$member instanceof Member) {
                throw new MemberNotFoundException('Member not found');
            }
        }

        $this->account = $account;
        $this->member = $member;
    }

    /**
     * 依帳號的 vendor_info_id 外鍵解析出商戶並塞進 $this->vendorInfo，順帶驗歸屬；不驗商戶 status（理由見 trait 說明）。
     *
     * 歸屬：帳號的 vendor_info_id 若指到別家遊戲商，少了這道會帶著別家的 rate／currency／密鑰繼續算錢。
     * 先存區域變數再判型別：BelongsTo 回的是 Model|null，直接指派給 ?VendorInfo 型別對不上；
     * 且 is_object() 對任何 Model 都成立，narrowing 不到 VendorInfo。
     *
     * @throws VendorNotFoundException
     */
    protected function resolveVendorInfo(AbstractAccount $account): void
    {
        $vendorInfo = $account->vendorInfo;

        if (!$vendorInfo instanceof VendorInfo) {
            throw new VendorNotFoundException('Vendor info not found');
        }

        if ((int) $vendorInfo->vendor_id !== $this->vendorId) {
            throw new VendorNotFoundException('Vendor info not found');
        }

        $this->vendorInfo = $vendorInfo;
    }

    /**
     * 以三方帳號解析出 {vendor}_account、member 與 vendor_info 並塞進對應成員變數。
     *
     * @throws VendorAccountException|MemberNotFoundException|VendorNotFoundException
     */
    protected function setUpVendorAccount(string $thirdPartyUsername, array $whereConditions = []): void
    {
        $baseWhere = [
            ['username', $thirdPartyUsername],
        ];

        $this->account = $this->accountClass::query()
            ->where([
                ...$baseWhere,
                ...$whereConditions,
            ])
            ->first();

        if (!$this->account || $this->account->member_id <= 0) {
            throw new VendorAccountException('User not found');
        }

        $this->member = Member::find($this->account->member_id);

        if (!$this->member) {
            throw new MemberNotFoundException('User Disabled');
        }

        // 先存區域變數再判型別：BelongsTo 回的是 Model|null，直接指派給 ?VendorInfo 型別對不上；
        // 且原本的 is_object() 對任何 Model 都成立，narrowing 不到 VendorInfo。
        $vendorInfo = $this->account->vendorInfo;
        if (!$vendorInfo instanceof VendorInfo) {
            throw new VendorNotFoundException('Vendor info not found');
        }

        $this->vendorInfo = $vendorInfo;
    }

    protected function getMemberByThirdPartyUsername(string $thirdPartyUsername): ?Member
    {
        $account = $this->accountClass::query()->where('username', $thirdPartyUsername)->first();
        if (!$account || $account->member_id <= 0) {
            return null;
        }
        $this->account = $account;
        $member = Member::find($account->member_id);

        return $member;
    }

    protected function getMemberBySessionToken($sessionToken): ?Member
    {
        $account = $this->accountClass::query()->where('session_token', $sessionToken)->first();
        if (!$account || $account->member_id <= 0) {
            return null;
        }
        $this->account = $account;
        $member = Member::find($account->member_id);

        return $member;
    }

    protected function getVendorInfo(string $operatorId = '', ?AbstractAccount $account = null): ?VendorInfo
    {
        $vendorInfos = VendorInfo::query()
            ->where('vendor_id', $this->vendorId)
            ->where('status', 1)
            ->when($account, function ($query) use ($account) {
                return $query->where('id', $account->vendor_info_id);
            })
            ->get();

        foreach ($vendorInfos as $vendorInfo) {
            if (empty($operatorId)) {
                // 如果沒有指定代理 則回傳第一筆
                return $vendorInfo;
            }

            if ($vendorInfo->info[$this->vendorInfoOperatorField] == $operatorId) {
                return $vendorInfo;
            }
        }

        return null;
    }
}
