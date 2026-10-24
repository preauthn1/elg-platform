<?php

declare(strict_types=1);

namespace App\Vendors\FumaSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\FumaSW\Datas\BetInfoData;
use App\Vendors\FumaSW\Datas\SettleInfoData;
use App\Vendors\FumaSW\Datas\VendorInfoDetailData;
use App\Vendors\FumaSW\Enums\ErrorCodeEnum;
use App\Vendors\FumaSW\Libs\Bet;
use App\Vendors\FumaSW\Libs\LibServiceInterface;
use App\Vendors\FumaSW\Libs\Settle;
use App\Vendors\Models\FumaswAccount;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @knowledge rules/vendor-fumasw
 */
class FumaSWService
{
    private array $vendorId = [
        VendorIdEnum::FUMASW->value,
        VendorIdEnum::FUMASW_FREE->value, // 免盤費商戶
    ];

    private FumaswAccount $account;

    private VendorInfo $vendorInfo;

    private Member $member;

    private VendorInfoDetailData $infoDetail;

    public function decodeRequestAndValidateSignature(Request &$request): bool
    {
        $dc = $request->input('dc');
        $data = $request->input('x');

        $this->setVendorInfo($dc);
        $this->infoDetail = VendorInfoDetailData::from($this->vendorInfo->info);

        $data = str_replace(['-', '_'], ['+', '/'], $data);
        $data = base64_decode($data);
        $decrypted = openssl_decrypt($data, 'AES-128-CBC', $this->infoDetail->KEY, OPENSSL_NO_PADDING, $this->infoDetail->IV);
        $decrypted = $this->pkcs7_unpad($decrypted);
        $decrypted = json_decode($decrypted, true);

        if (empty($decrypted)) {
            throw new Exception('Decrypt data failed', ErrorCodeEnum::SYSTEM_ERROR);
        }

        $request->merge($decrypted);

        return true;
    }

    public function getBalance(string $username): string
    {
        $account = $this->getAccount($username);
        $member = $account->member;

        if ($account->status == 0) {
            throw new Exception('Account is disabled', ErrorCodeEnum::ACCOUNT_NOT_FOUND);
        }

        $ableToBet = BetHelper::isMemberFlowLimitAllowed($member, $this->vendorInfo->vendor_id);
        if (!$ableToBet) {
            throw new Exception('Account could not bet yet', ErrorCodeEnum::ACCOUNT_NOT_FOUND);
        }

        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $member);

        return $balance;
    }

    public function bet(Request $request): string
    {
        $result = $this->handle(new Bet, new BetInfoData($request->all()));

        return $result;
    }

    public function settle(Request $request): string
    {
        $result = $this->handle(new Settle, new SettleInfoData($request->all()));

        return $result;
    }

    private function handle(LibServiceInterface $libService, BetInfoData|SettleInfoData $dto): string
    {
        $username = $dto->username;
        $account = $this->getAccount($username);

        // Named lock 序列化同 round+username 的並發請求，避免 fumasw_bet_records
        // 在 round_id 索引上的 SELECT FOR UPDATE / INSERT 互鎖死結。
        // selectFromWriteConnection 強制走 write PDO，否則讀寫分離下 GET_LOCK
        // 會落在 read replica，與後續 transaction 不同連線。
        $lockKey = 'fumasw:' . md5("{$dto->roundId}:{$username}");
        $acquired = DB::selectFromWriteConnection('SELECT GET_LOCK(?, ?) AS l', [$lockKey, 5]);
        if (empty($acquired) || (int) $acquired[0]->l !== 1) {
            throw new Exception(
                "Concurrent request on round {$dto->roundId}",
                ErrorCodeEnum::TRANSACTIONID_DUPLICATE
            );
        }

        $result = 0.00;
        try {
            DB::beginTransaction();

            $libService->setSettings($dto, $this->vendorInfo, $account);
            $result = $libService->exec();

            DB::commit();
        } catch (QueryException $e) {
            DB::rollBack();

            $errorCode = $e->errorInfo[1];
            if ($errorCode === 1062) { // Duplicate entry, ignore it.
                throw new Exception("Record duplicated: {$dto->transferId}", ErrorCodeEnum::TRANSACTIONID_DUPLICATE);
            }

            throw new Exception($e->getMessage(), ErrorCodeEnum::SYSTEM_ERROR);
        } catch (Exception $e) {
            DB::rollBack();

            $code = $e->getCode() === 0 ? ErrorCodeEnum::SYSTEM_ERROR : $e->getCode();
            throw new Exception($e->getMessage(), $code);
        } finally {
            DB::selectFromWriteConnection('SELECT RELEASE_LOCK(?)', [$lockKey]);
        }

        return $result;
    }

    private function getAccount(string $username): FumaswAccount
    {
        if (isset($this->account)) {
            return $this->account;
        }
        $account = FumaswAccount::with('member', 'vendorInfo')
            ->where('username', $username)
            ->first();
        if (empty($account)) {
            throw new Exception('Account not found', ErrorCodeEnum::ACCOUNT_NOT_FOUND);
        }

        $member = $account->member;
        if (!is_object($member) || $member->sys_lock === 1) {
            throw new Exception('Member not active', ErrorCodeEnum::ACCOUNT_NOT_FOUND);
        }

        $this->account = $account;
        $this->member = $account->member;

        return $this->account;
    }

    private function setVendorInfo(string $dc): void
    {
        // dc 來自未經驗證的回調參數，且這一步先於解密驗證，必須以綁定參數查詢（op_tickets#3097）
        $vendorInfo = VendorInfo::whereIn('vendor_id', $this->vendorId)
            ->whereRaw("JSON_EXTRACT(info, '$.DC') = ?", [$dc])
            ->first();

        // 先判空再指派：屬性型別為 VendorInfo，直接指派 null 會先拋 TypeError，讓下方的業務錯誤永遠到不了
        if (!$vendorInfo instanceof VendorInfo) {
            throw new Exception('Vendor not found', ErrorCodeEnum::SYSTEM_ERROR);
        }

        $this->vendorInfo = $vendorInfo;
    }

    private function pkcs7_unpad($data)
    {
        $len = strlen($data);
        if ($len === 0) {
            return $data;
        }
        $pad = ord($data[$len - 1]);
        if ($pad > 16) {
            return $data;
        } // padding 不合法

        return substr($data, 0, $len - $pad);
    }
}
