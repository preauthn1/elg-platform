<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets\Contracts;

/**
 * 與 {@see ShouldResolveVendorAccount} 同掛才有作用：列出允許「帳號存在但尚未綁定會員（member_id = 0）」
 * 的 action 名稱。這些 action 解析後 $this->member 可能是 null，action 內要自己判斷。
 *
 * 典型用途：三方在玩家首次進入前先打「查玩家是否存在」或「查餘額」，此時池帳號還沒配給任何會員。
 */
interface AllowsUnboundAccount
{
    /**
     * @return list<string> 允許未綁定帳號的 action method 名稱
     */
    public function methodsAllowingUnboundAccount(): array;
}
