<?php

declare(strict_types=1);

namespace App\Vendors\VpowerSW;

use App\Helpers\BetHelper;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\VpowerswAccount;
use App\Vendors\VpowerSW\Datas\BetInfoData;
use App\Vendors\VpowerSW\Enums\ErrorCodeEnum;
use App\Vendors\VpowerSW\Libs\Bet;
use App\Vendors\VpowerSW\Libs\LibServiceInterface;
use App\Vendors\VpowerSW\Libs\Refund;
use App\Vendors\VpowerSW\Libs\Settlement;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class VpowerSWService
{
    public static function getBalance(string $username): string
    {
        [$account, $vendorInfo] = self::getAccounts($username);
        $member = $account->member;

        if ($account->status == 0) {
            throw new Exception('Account is disabled', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
        }

        $ableToBet = BetHelper::isMemberFlowLimitAllowed($member, $vendorInfo->vendor_id);
        if (!$ableToBet) {
            throw new Exception('Account could not bet yet', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
        }

        return (new WalletService)->getSingleWalletBalanceByMember($vendorInfo, $member);
    }

    public static function bet(BetInfoData $dto): string
    {
        return self::handler($dto, new Bet);
    }

    public static function settlement(BetInfoData $dto): string
    {
        return self::handler($dto, new Settlement);
    }

    public static function refund(BetInfoData $dto): string
    {
        return self::handler($dto, new Refund);
    }

    private static function handler(BetInfoData $dto, LibServiceInterface $libService): string
    {
        [$account, $vendorInfo] = self::getAccounts($dto->username);

        $result = 0.00;
        try {
            DB::beginTransaction();

            $libService->setSettings($dto, $vendorInfo, $account);
            $result = $libService->exec();

            DB::commit();
        } catch (QueryException $e) {
            DB::rollBack();

            $errorCode = $e->errorInfo[1];
            if ($errorCode === 1062) { // Duplicate entry, ignore it.
                throw new Exception('Bet record duplicated', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
            }

            throw new Exception($e->getMessage(), ErrorCodeEnum::OTHER_EXCEPTIONS->value);
        } catch (Exception $e) {
            DB::rollBack();

            $code = $e->getCode() === 0 ? ErrorCodeEnum::OTHER_EXCEPTIONS->value : $e->getCode();
            throw new Exception($e->getMessage(), $code);
        }

        return $result;
    }

    private static function getAccounts(string $username): array
    {
        $account = app()->has(VpowerswAccount::class) ?
                    app()->make(VpowerswAccount::class) :
                    VpowerswAccount::where('username', $username)->first();
        if (!$account) {
            throw new Exception('User not found, app not bind', ErrorCodeEnum::USER_NOT_FOUND->value);
        }

        $vendorInfo = app()->has(VendorInfo::class) ?
                    app()->make(VendorInfo::class) :
                    $account->vendorInfo;
        if (!$vendorInfo) {
            throw new Exception('Vendor info not found, app not bind', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
        }

        return [$account, $vendorInfo];
    }
}
