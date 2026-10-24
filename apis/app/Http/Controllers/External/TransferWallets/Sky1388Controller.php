<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\TransferWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use App\Vendors\Models\Sky1388Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Sky1388 Confirm Transfer Callback（防偽造上下分）
 *
 * 玩家上/下分時 Sky1388 反向呼叫此端點，確認該筆 user_transfer 確實來自本系統。
 * 從 API_External（舊系統）VWorld 分支搬移過來（VWorld 已用此端點多時，WTT 因是
 * 獨立部署的舊系統分支，先前未同步到這支，直到 Sky1388 商戶更換才踩到
 * PLEASE_SET_CONFIRM_TRANSFER_CALLBACK）。
 *
 * @see docs/integrations/vendors/Sky1388/api.md#confirm-transfer-callback
 *
 * @knowledge rules/vendor-sky1388
 */
class Sky1388Controller extends Controller
{
    protected int $vendorId = VendorIdEnum::SKY1388->value;

    public function confirmTransfer(Request $request)
    {
        Log::info('Sky1388_ConfirmTransfer', [
            'headers' => Common::flattenHeaders($request->headers->all()),
            'params' => Common::maskCredentials($request->all()),
            'IP' => Common::getIp(),
        ]);

        if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
            return response()->json([
                'status' => '9999',
                'err_message' => sprintf('IP %s not allow', Common::getIp()),
            ], 401);
        }

        $request->validate([
            'kiosk' => 'required|string',
            'order' => 'required|string',
            'amount' => 'required|numeric',
        ]);

        $account = Sky1388Account::where('username', $request->input('kiosk'))
            ->where('member_id', '>', 0)
            ->first();

        if (!$account) {
            return response()->json([
                'status' => '9999',
                'err_message' => 'Account not found',
            ]);
        }

        // 轉帳跟回調的時間差太短，用分庫查會查不到，改走主庫
        $transactionLog = TransactionLog::onWriteConnection()
            ->where('transaction_no', $request->input('order'))
            ->where('vendor_id', $this->vendorId)
            ->where('member_id', $account->member_id)
            ->first();

        if (!$transactionLog) {
            $transactionLog = WalletTransactionLog::onWriteConnection()
                ->where('transaction_no', $request->input('order'))
                ->where('vendor_id', $this->vendorId)
                ->where('member_id', $account->member_id)
                ->first();
        }

        if (!$transactionLog) {
            return response()->json([
                'status' => '9999',
                'err_message' => 'Transaction not found',
            ]);
        }

        return response()->json([
            'status' => '0000',
            'err_message' => '',
        ]);
    }
}
