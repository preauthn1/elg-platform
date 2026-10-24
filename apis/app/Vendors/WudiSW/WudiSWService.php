<?php

declare(strict_types=1);

namespace App\Vendors\WudiSW;

use App\Helpers\BetHelper;
use App\Services\WalletService;
use App\Vendors\Models\WudiswAccount;
use App\Vendors\WudiSW\Datas\TransferInfoData;
use App\Vendors\WudiSW\Datas\VendorInfoDetailData;
use App\Vendors\WudiSW\Enums\ErrorCodeEnum;
use App\Vendors\WudiSW\Libs\LibServiceInterface;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @knowledge rules/vendor-wudisw
 */
class WudiSWService
{
    /**
     * @var WudiswAccount
     */
    private $account;

    /**
     * @var VendorInfoDetailData
     */
    private $infoDetail;

    public function validate(Request $request): bool
    {
        $username = $request->input('username');
        $this->loadDatas($username);

        $params = $request->all();
        unset($params['trans_details'], $params['betform_details'], $params['auth_code']);
        ksort($params);

        $paramsString = '';
        foreach ($params as $key => $value) {
            $paramsString .= "{$key}={$value}&";
        }

        return md5($paramsString . $this->infoDetail->MD5_KEY) === $request->input('auth_code');
    }

    public function getBalance(string $username): string
    {
        $this->loadDatas($username);

        if ($this->account->status == 0) {
            throw new Exception('Account not enabled', ErrorCodeEnum::PLAYER_ACCOUNT_SUSPENDED->value);
        }

        $ableToBet = BetHelper::isMemberFlowLimitAllowed($this->account->member, $this->account->vendorInfo->vendor_id);
        if (!$ableToBet) {
            throw new Exception('Account could not bet yet', ErrorCodeEnum::PLAYER_ACCOUNT_SUSPENDED->value);
        }

        return (new WalletService)->getSingleWalletBalanceByMember($this->account->vendorInfo, $this->account->member);
    }

    public function getCurrency(): string
    {
        return $this->account->vendorInfo->currency;
    }

    /**
     * 取目前餘額（不驗帳號狀態／流水限制），供重複請求（code 1000）回應帶 data 用
     */
    public function getCurrentBalance(string $username): string
    {
        $this->loadDatas($username);

        return (new WalletService)->getSingleWalletBalanceByMember($this->account->vendorInfo, $this->account->member);
    }

    public function transferStart(TransferInfoData $dto): string
    {
        if ($dto->amount == 0.00) {
            // 若 transfer_type 為 start，且 amount = 0 : 只能回 status 1000，若回應其他狀態代碼，則該玩家可能會被限制遊戲
            try {
                return (new WalletService)->getSingleWalletBalanceByMember($this->account->vendorInfo, $this->account->member);
            } catch (Exception $e) {
                return '0.00';
            }
        }

        return $this->handleTransfer(new Libs\Start, $dto);
    }

    public function transferEnd(TransferInfoData $dto): string
    {
        return $this->handleTransfer(new Libs\End, $dto);
    }

    public function transferRefund(TransferInfoData $dto): string
    {
        return $this->handleTransfer(new Libs\Refund, $dto);
    }

    public function transferReward(TransferInfoData $dto): string
    {
        return $this->handleTransfer(new Libs\Reward, $dto);
    }

    private function handleTransfer(LibServiceInterface $lib, TransferInfoData $dto): string
    {
        $this->loadDatas($dto->username);

        try {
            DB::beginTransaction();

            $lib->setSettings($dto, $this->account->vendorInfo, $this->account);
            $result = $lib->exec();

            DB::commit();

            return $result;
        } catch (QueryException $e) {
            DB::rollBack();

            $errorCode = $e->errorInfo[1];
            if ($errorCode === 1062) { // Duplicate entry, ignore it.
                throw new Exception('Duplicate entry', ErrorCodeEnum::DUPLICATE_TRAN_ID->value);
            }

            throw new Exception($e->getMessage(), ErrorCodeEnum::SOMETHING_WRONG->value);
        } catch (Exception $e) {
            DB::rollBack();

            $code = $e->getCode() === 0 ? ErrorCodeEnum::SOMETHING_WRONG->value : $e->getCode();
            throw new Exception($e->getMessage(), $code);
        }
    }

    private function loadDatas(string $username): void
    {
        if (isset($this->account)) {
            return;
        }

        $account = WudiswAccount::with('vendorInfo', 'member')
            ->where('username', $username)
            ->first();
        if (!$account) {
            throw new Exception('User not found', ErrorCodeEnum::AUTHORIZATION_INVALID->value);
        }
        if (!$account->vendorInfo) {
            throw new Exception('Vendor info not found', ErrorCodeEnum::AUTHORIZATION_INVALID->value);
        }
        if (!$account->member) {
            throw new Exception('Member not found', ErrorCodeEnum::AUTHORIZATION_INVALID->value);
        }

        $this->account = $account;
        $this->infoDetail = VendorInfoDetailData::from($account->vendorInfo->info);
    }
}
