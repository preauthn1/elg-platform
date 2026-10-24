<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WFSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\WfswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class WFSWTestCase extends TestCase
{
    protected string $operatorId = 'wfTESTMYR';

    protected string $secretKey = 'testSecretKey';

    protected string $acctId = 'WFSW00004460';

    protected string $gameCode = '70012';

    protected string $baseurl = '/external/api/sw/wf';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected WfswAccount $account;

    protected Member|MemberWallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::WFSW->value);
        $this->vendor->save();

        // 對齊測試使用的 GameCode，避免 BetHelper::isMemberAbleToBet 找不到 game 而被擋下
        $this->vendor->games()->first()->update(['game_type' => $this->gameCode]);

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->account_prefix = 'WFSW';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->rolling_chip_status = 0;
        $this->vendorInfo->info = [
            'API_URL' => '',
            'OperatorId' => $this->operatorId,
            'SecretKey' => $this->secretKey,
            'CURRENCY' => 'MYR',
        ];
        $this->vendorInfo->save();

        $this->account = WfswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->acctId,
            'admin' => 1,
            'member_id' => $this->member->id,
        ]);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->wallet = $walletDto->wallet;
    }

    /**
     * 簽名規則同 WFController::checkSign()：
     * MD5(FunctionName + 該功能的唯一 ID + RequestDateTime + OperatorId + SecretKey + PlayerId)
     */
    protected function regenerateSignature(string $functionName, array &$postData): void
    {
        $uniqueId = match ($functionName) {
            'Bet', 'Rollback' => (string) ($postData['BetId'] ?? ''),
            'GameResult' => (string) ($postData['ResultId'] ?? ''),
            'CashBonus', 'Jackpot' => (string) ($postData['TranId'] ?? ''),
            default => '',
        };

        $postData['Signature'] = md5(
            $functionName . $uniqueId . $postData['RequestDateTime'] . $postData['OperatorId'] . $this->secretKey . $postData['PlayerId']
        );
    }

    protected function basePostData(): array
    {
        return [
            'OperatorId' => $this->operatorId,
            'PlayerId' => $this->acctId,
            'Currency' => 'MYR',
            'RequestDateTime' => '2026-09-22 04:54:20',
            'Signature' => '',
        ];
    }
}
