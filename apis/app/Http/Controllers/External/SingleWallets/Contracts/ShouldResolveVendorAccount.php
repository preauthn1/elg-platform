<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets\Contracts;

use App\Http\Controllers\External\SingleWallets\Data\AccountIdentifier;
use Illuminate\Http\Request;

/**
 * 掛上後 {@see \App\Http\Controllers\External\SingleWallets\BaseController::callAction()} 會在進 action 前
 * 以 {@see self::accountIdentifier()} 回傳的識別子解析出 account、member 與 vendorInfo，
 * 塞進對應成員變數；解析失敗拋 VendorAccountException／MemberNotFoundException／VendorNotFoundException。
 *
 * 解析成功後一律再驗一件事，不可關閉：vendorInfo 的 vendor_id 必須等於本 controller 的 $vendorId
 * （否則帶著別家的 rate／currency／密鑰算錢）。
 *
 * 🔴 不驗 account.status 與 vendorInfo.status：停用後仍要能結算、取消、查餘額，否則已扣的注金結不了。
 * 「現在能不能下注」由扣款類 action 呼叫 verifyBettability() 驗（BetHelper::assertMemberAbleToBet 連同帳號 status 一起擋）。
 *
 * 預設帳號必須已綁定會員（member_id > 0）。部分 endpoint 允許尚未綁定的池帳號（例如查玩家是否存在）時，
 * 另掛 {@see AllowsUnboundAccount} 指定 method。
 *
 * 🔴 掛上的同時必須刪掉子類 callAction 內自己的 setUpVendorAccount()／getMemberBy*() 呼叫。
 */
interface ShouldResolveVendorAccount
{
    /**
     * 從請求決定用什麼指認帳號：{@see AccountIdentifier::username()} 或 {@see AccountIdentifier::sessionToken()}。
     *
     * 欄位名每家不同（acctId／PlayerId／route 參數 account／sessionToken），用哪一種也可能依請求內容或 $method 而定
     * （例如有 userId 就用 username、沒有才用 token；或 getAuth 用 token、其餘用 user_id），由子類決定。
     * 值取不到時回空字串的識別子，共用層會以 VendorAccountException 拒絕。
     * 以 session_token 查無帳號時拋 {@see \App\Exceptions\SessionTokenNotFoundException}（繼承 VendorAccountException）。
     *
     * 回 null 表示這個 $method 不從請求解析帳號，共用層整段跳過、三個成員變數維持 null。
     * 用在請求只帶單號的結算型 action：action 內先查注單，再以注單上的帳號呼叫
     * {@see \App\Http\Controllers\External\SingleWallets\Concerns\ResolvesAccountAndVendorInfo::resolveAccount()}
     * 與 {@see \App\Http\Controllers\External\SingleWallets\Concerns\ResolvesAccountAndVendorInfo::resolveVendorInfo()}，
     * 商戶歸屬照樣驗。會被三方重送的動錢回調不可用會輪替的 session_token 認人，只有登入驗證與下注當下可以。
     */
    public function accountIdentifier(Request $request, string $method): ?AccountIdentifier;
}
