<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Data\VendorResetPasswordData;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\VendorSetupException;
use App\Models\Member;
use App\Models\Vendor;
use App\Services\VendorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

/**
 * php artisan vendor:test_reset_password {memberId} {vendorId} {vendorUsername} {newPassword}
 * php artisan vendor:test_reset_password 911962 92 CQ900005214 654321
 */
class TestResetPassword extends Command
{
    protected $signature = 'vendor:test_reset_password {memberId} {vendorId} {vendorUsername} {newPassword}';

    protected $description = '測試指定會員的遊戲帳號 resetPassword（跟 Agent VendorController::resetPassword 同一段邏輯，略過 HTTP/權限驗證）';

    public function handle(VendorService $vendorService): int
    {
        $memberId = (int) $this->argument('memberId');
        $vendorId = (int) $this->argument('vendorId');
        $vendorUsername = (string) $this->argument('vendorUsername');
        $newPassword = [REDACTED] $this->argument('newPassword');

        $member = Member::find($memberId);
        if (!$member) {
            $this->error('Member 不存在：' . $memberId);

            return self::FAILURE;
        }

        $vendor = Vendor::find($vendorId);
        if (!$vendor) {
            $this->error('Vendor 不存在：' . $vendorId);

            return self::FAILURE;
        }

        $vendorDriver = $vendorService->getDriver($vendorId, $member);

        $accountModel = App::make($vendorDriver->getAccountModel());
        $account = $accountModel
            ->where('vendor_info_id', $vendorDriver->vendorInfo->id)
            ->where('member_id', $member->id)
            ->where($accountModel->getVendorUsernameColumn(), $vendorUsername)
            ->first();

        if (!$account) {
            $this->error(sprintf(
                '找不到遊戲帳號：member_id=%d, vendor_id=%d, vendor_username=%s',
                $memberId,
                $vendorId,
                $vendorUsername
            ));

            return self::FAILURE;
        }

        $vendorDriver->setVendorAccount($account);

        $params = VendorResetPasswordData::from([
            'newPassword' => $newPassword,
        ]);

        try {
            $result = $vendorDriver->resetPassword($params);
        } catch (VendorSetupException $e) {
            $this->error($vendor->name . ' 未實作 resetPassword：' . $e->getMessage());

            return self::FAILURE;
        }

        if ($result === VendorTransferResultEnum::SUCCESS) {
            $this->info(sprintf('%s 密碼重設成功（member_id=%d, vendor_username=%s）', $vendor->name, $memberId, $vendorUsername));

            return self::SUCCESS;
        }

        $this->error(sprintf('%s 密碼重設失敗：result=%s', $vendor->name, $result->name));

        return self::FAILURE;
    }
}
