<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\CosmoPlaySW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\CosmoPlaySWAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class CosmoPlaySWTestCase extends TestCase
{
    protected $baseurl = '/external/api/sw/cosmoplay';

    protected $partnerCode = 'TWER_MYR';

    protected $username = 'cosmo00000001';

    protected $authToken = [REDACTED]';

    protected $gameId = '07EE';

    protected $jwtSecret = [REDACTED]';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected CosmoPlaySWAccount $account;

    protected Member|MemberWallet $wallet;

    protected ?MemberWallet $rollingChipWallet = null;

    protected Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::COSMOPLAYSW->value);
        $this->vendor->name = 'CosmoPlaySW';
        $this->vendor->code = 'cosmoplaysw';
        $this->vendor->tname = 'cosmoplaysw';
        $this->vendor->third_party_code = 'COSMOPLAYSW';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->name = 'cosmoplaysw';
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1;
        $this->vendorInfo->currency = 'MYR';
        // VendorInfo::info() accessor 只定義 get、沒有 set closure，$casts=['info'=>'array'] 仍會在寫入時生效，
        // 所以這裡要指派原始陣列讓 cast 做唯一一次 json_encode；若先手動 json_encode 會被再包一層導致雙重編碼，
        // 讀出時退化成字串，CosmoPlaySWController::getVendorInfo() 對字串做陣列 offset 存取會直接 500。
        $this->vendorInfo->info = [
            'API_URL' => 'https://example.invalid',
            'H5_URL' => 'https://example.invalid',
            'PARTNER_CODE' => $this->partnerCode,
            'JWT_SECRET' => $this->jwtSecret,
        ];
        $this->vendorInfo->save();

        $this->game = $this->vendor->games()->first();
        $this->game->game_type = $this->gameId;
        $this->game->status = 1;
        $this->game->save();

        $this->account = CosmoPlaySWAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'session_token' => $this->authToken,
            'member_id' => $this->member->id,
            'status' => 1,
        ]);

        $walletDatas = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->wallet = $walletDatas->wallet;
        $this->rollingChipWallet = $walletDatas->rollingChipWallet;

        $this->wallet->update(['balance' => 1000]);
    }

    /**
     * 依 CosmoPlay 規格生成 JWT（HS256，claims = 完整 payload），與
     * App\Vendors\CosmoPlaySW\Driver::generateJwt() 邏輯一致，測試端獨立實作避免測試與production程式碼耦合。
     */
    protected function generateCosmoPlayJwt(array $payload, ?string $secret = [REDACTED] string
    {
        $secret ??= $this->jwtSecret;

        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        $signingInput = $header . '.' . $payloadEncoded;
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $signingInput, $secret, true));

        return $signingInput . '.' . $signature;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
