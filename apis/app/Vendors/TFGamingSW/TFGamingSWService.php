<?php

declare(strict_types=1);

namespace App\Vendors\TFGamingSW;

use App\Helpers\BetHelper;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\TfgamingswAccount;
use App\Vendors\TFGamingSW\Datas\TransferInfoData;
use App\Vendors\TFGamingSW\Enums\ErrorCodeEnum;
use App\Vendors\TFGamingSW\Libs\Bet;
use App\Vendors\TFGamingSW\Libs\LibServiceInterface;
use App\Vendors\TFGamingSW\Libs\Rollback;
use App\Vendors\TFGamingSW\Libs\Settle;
use App\Vendors\TFGamingSW\Libs\Unsettle;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class TFGamingSWService
{
    public static function getBalance(string $username): string
    {
        [$account, $vendorInfo] = self::getAccounts($username);
        $member = $account->member;

        if ($account->status == 0) {
            throw new Exception('Account is disabled', ErrorCodeEnum::INVALID_MEMBER_STATE->value);
        }

        $ableToBet = BetHelper::isMemberFlowLimitAllowed($member, $vendorInfo->vendor_id);
        if (!$ableToBet) {
            throw new Exception('Account could not bet yet', ErrorCodeEnum::INVALID_MEMBER_STATE->value);
        }

        $balance = (new WalletService)->getSingleWalletBalanceByMember($vendorInfo, $member);

        return $balance;
    }

    public static function bet(TransferInfoData $transferInfoDTO)
    {
        return self::handle(new Bet, $transferInfoDTO);
    }

    public static function settle(TransferInfoData $transferInfoDTO)
    {
        return self::handle(new Settle, $transferInfoDTO);
    }

    public static function unsettle(TransferInfoData $transferInfoDTO)
    {
        return self::handle(new Unsettle, $transferInfoDTO);
    }

    public static function rollback(TransferInfoData $transferInfoDTO)
    {
        return self::handle(new Rollback, $transferInfoDTO);
    }

    private static function handle(LibServiceInterface $libService, TransferInfoData $transferInfoDTO)
    {
        [$account, $vendorInfo] = self::getAccounts($transferInfoDTO->username);

        $result = 0.00;
        try {
            DB::beginTransaction();

            $libService->setSettings($transferInfoDTO, $vendorInfo, $account);
            $result = $libService->exec();

            DB::commit();
        } catch (QueryException $e) {
            DB::rollBack();

            $errorCode = $e->errorInfo[1] ?? 0;
            if ($errorCode === 1062) { // Duplicate entry, ignore it.
                throw new Exception('Bet record duplicated', ErrorCodeEnum::UNDEFINED->value);
            }

            throw new Exception($e->getMessage(), ErrorCodeEnum::UNDEFINED->value);
        } catch (Exception $e) {
            DB::rollBack();

            $code = $e->getCode() === 0 ? ErrorCodeEnum::UNDEFINED->value : $e->getCode();
            throw new Exception($e->getMessage(), $code);
        }

        return $result;
    }

    private static function getAccounts(string $username): array
    {
        $account = app()->has(TfgamingswAccount::class) ?
                    app()->make(TfgamingswAccount::class) :
                    TfgamingswAccount::where('username', $username)->first();
        if (!$account) {
            throw new Exception('User not found, app not bind', ErrorCodeEnum::USER_NOT_FOUND->value);
        }

        $vendorInfo = app()->has(VendorInfo::class) ?
                    app()->make(VendorInfo::class) :
                    $account->vendorInfo;
        if (!$vendorInfo) {
            throw new Exception('Vendor info not found, app not bind', ErrorCodeEnum::INVALID_OPERATOR->value);
        }

        return [$account, $vendorInfo];
    }
}
