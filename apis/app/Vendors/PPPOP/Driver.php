<?php

declare(strict_types=1);

namespace App\Vendors\PPPOP;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\PpAccount;

/**
 * PP POP（Pragmatic Play 轉帳錢包，獨立憑證，共用 pp_accounts / pp_bet_records）
 *
 * 沿用 Pp\Driver 的轉帳／查餘額／簽名邏輯，僅新增 createAccount()：
 * 現有 Pp\Driver 未實作建帳號流程（三方 CreatePlayer 端點原本沒被呼叫），
 * PPPOP 需要走標準的帳號池預建流程，故在此補上。
 */
class Driver extends \App\Vendors\Pp\Driver implements ShouldScheduleAccountCreation
{
    /**
     * 建立我方遊戲商帳號，並呼叫 PP CreatePlayer 註冊三方帳號
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        /** @var PpAccount $account */
        $account = $this->accountModel::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $params->username,
        ]);

        if (!$account) {
            return CreateAccountResultData::from([
                'status' => false,
                'username' => $params->username,
            ]);
        }

        if (!$this->createVendorAccount($account)) {
            // 三方建立失敗，軟刪除避免帳號池污染
            $account->update(['member_id' => -999999999]);

            return CreateAccountResultData::from([
                'status' => false,
                'username' => $params->username,
            ]);
        }

        return CreateAccountResultData::from([
            'status' => true,
            'username' => $params->username,
        ]);
    }

    /**
     * 呼叫 PP CreatePlayer（/player/account/create/）建立三方帳號
     */
    protected function createVendorAccount(PpAccount $account): bool
    {
        $data = [
            'secureLogin' => $this->secretLogin,
            'externalPlayerId' => $account->username,
            'currency' => $this->vendorInfo->currency,
        ];

        $data['hash'] = $this->getHash($data);

        $result = $this->sendRequest('/CasinoGameAPI/player/account/create/', $data, 'createAccount');

        if (!is_array($result) || !isset($result['error'])) {
            return false;
        }

        if ((string) $result['error'] !== '0') {
            return false;
        }

        $account->update(['player_id' => (string) ($result['playerId'] ?? '0')]);

        return true;
    }
}
