<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MegaH5SW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\MegaH5SWAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class MegaH5TestCase extends TestCase
{
    protected string $operatorId = 'DemoOperator';

    protected string $secretKey = 'testSecretKey';

    protected string $acctId = '233_MEGAH5_00000001';

    protected string $baseurl = '/external/api/sw/megah5';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected MegaH5SWAccount $account;

    protected Member|MemberWallet $wallet;

    protected MemberWallet $rollingChipWallet;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::MEGAH5SW->value);
        $this->vendor->save();

        // 對齊測試使用的 GameCode '10000'，避免 BetHelper::isMemberAbleToBet 找不到 game 而被擋下
        $this->vendor->games()->first()->update(['game_type' => '10000']);

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->account_prefix = 'MG5_';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->rolling_chip_status = 1;
        $this->vendorInfo->info = [
            'API_URL' => '',
            'OPERATOR_ID' => $this->operatorId,
            'SECRET_KEY' => $this->secretKey,
        ];
        $this->vendorInfo->save();

        $this->account = MegaH5SWAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->acctId,
            'admin' => 1,
            'member_id' => $this->member->id,
            'auth_token' => null,
        ]);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->rollingChipWallet = $walletDto->rollingChipWallet;
        $this->wallet = $walletDto->wallet;
    }

    protected function regenerateSignature(string $functionName, array &$postData): void
    {
        $uniqueId = match ($functionName) {
            'Bet', 'Rollback' => (string) ($postData['BetId'] ?? ''),
            'GameResult', 'BetNSettle', 'CancelBetNSettle' => (string) ($postData['ResultId'] ?? ''),
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
            'RequestDateTime' => '2024-09-30T14:16:32Z',
            'Signature' => '',
        ];
    }
}
