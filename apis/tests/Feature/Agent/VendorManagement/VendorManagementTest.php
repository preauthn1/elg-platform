<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\VendorManagement;

use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Enums\Agent\RoleEnum;
use App\Enums\Vendor\VendorChannelEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\Vendor;
use App\Vendors\Contracts\HasAggregatorChannel;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\HasGameList;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 廠商管理 target/tickets#4400：選單權限、列表的接入通道／gamelist API／回調網址、回調後綴編輯。
 * 回調路徑只給帳號角色為 admin 的使用者看與改；setUp 建的代理是總代，涉及回調的案例先 promoteToAdmin()。
 *
 * Driver 以匿名類別覆寫 config('vendor.driver')，只需能被 is_a 判斷契約、不會被實例化。
 *
 * php artisan test tests/Feature/Agent/VendorManagement/VendorManagementTest.php
 */
class VendorManagementTest extends TestCase
{
    private const string EXTERNAL_URL = 'https://external.test/';

    private Agent $agent;

    /** 經 EliteHub 接入、有 gamelist API、宣告回調後綴的無縫錢包 */
    private Vendor $integratedVendor;

    /** 直連、沒宣告回調後綴的無縫錢包 */
    private Vendor $directVendor;

    /** 轉帳錢包 */
    private Vendor $transferVendor;

    /** config 沒掛 Driver 的遊戲商 */
    private Vendor $unmappedVendor;

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 AES 解密與日誌中間件，保留 AuthAgent + MergeAgentData 讓 agent context 正確注入
        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();
        $this->loginAs($this->agent);

        $this->integratedVendor = Vendor::factory()->create(['is_single_wallet' => 1]);
        $this->directVendor = Vendor::factory()->create(['is_single_wallet' => 1]);
        $this->transferVendor = Vendor::factory()->create(['is_single_wallet' => 0]);
        $this->unmappedVendor = Vendor::factory()->create(['is_single_wallet' => 1]);

        config()->set('vendor.driver', [
            $this->integratedVendor->id => $this->integratedDriverClass(),
            $this->directVendor->id => $this->plainDriverClass(),
            $this->transferVendor->id => $this->plainDriverClass(),
        ]);
        config()->set('custom.api_external_url', self::EXTERNAL_URL);
    }

    private function loginAs(Agent $agent): void
    {
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * 把登入中的代理改成 admin 角色（token 不變，每次請求都從 DB 重讀代理）
     */
    private function promoteToAdmin(): void
    {
        $this->agent->forceFill(['role_id' => RoleEnum::ADMIN->value])->save();
    }

    private function createMenu(): AgentMenu
    {
        return AgentMenu::create([
            'code' => 'vendor_management',
            'name' => '廠商管理',
            'parent' => 0,
            'url' => 'vendor/index',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);
    }

    private function grantMenu(Agent $agent): void
    {
        $menu = AgentMenu::query()->where('code', 'vendor_management')->first() ?? $this->createMenu();

        AgentPermission::create([
            'agent_id' => $agent->id,
            'agent_menu_id' => $menu->id,
            'action' => '',
            'admin' => 0,
        ]);
    }

    private function list(): TestResponse
    {
        return $this->requestApi('agent/api/vendor-management/list');
    }

    private function updateCallbackPath(Vendor $vendor, ?string $path): TestResponse
    {
        return $this->requestApi('agent/api/vendor-management/callback-path', [
            'id' => $vendor->id,
            'callback_path' => $path,
        ]);
    }

    /**
     * 取列表中指定遊戲商的那一列（測試庫可能有其他遊戲商，不依位置取）
     *
     * @return array<string, mixed>
     */
    private function rowOf(TestResponse $response, Vendor $vendor): array
    {
        $row = collect($response->json('data.items'))->firstWhere('id', $vendor->id);
        $this->assertIsArray($row, "列表缺少 vendor {$vendor->id}");

        return $row;
    }

    #[TestDox('沒有 vendor_management 選單權限的代理不得呼叫')]
    #[Group('rules/vendor-and-channel-management#R-1')]
    public function test_rejects_agent_without_menu_permission(): void
    {
        $this->createMenu();

        $this->list()->assertJsonPath('message', __('error_message.9003'));
        $this->updateCallbackPath($this->integratedVendor, '/external/api/sw/custom')
            ->assertJsonPath('message', __('error_message.9003'));
    }

    #[TestDox('列表：接入通道、gamelist API、回調適用與完整網址依 Driver 契約與錢包模式推導')]
    #[Group('rules/vendor-and-channel-management#R-10')]
    #[Group('rules/vendor-and-channel-management#R-3')]
    #[Group('rules/vendor-and-channel-management#R-4')]
    #[Group('rules/vendor-and-channel-management#R-6')]
    #[Group('rules/vendor-and-channel-management#R-8')]
    #[Group('rules/vendor-and-channel-management#R-9')]
    public function test_list_derives_driver_capabilities(): void
    {
        $this->promoteToAdmin();
        $this->grantMenu($this->agent);

        $response = $this->list();

        $response->assertJsonPath('code', 200)
            ->assertJsonPath('data.callback_visible', true)
            ->assertJsonPath('data.callback_base_url', 'https://external.test');

        $channels = $response->json('data.channels');
        $this->assertContains('direct', $channels);
        $this->assertContains(VendorChannelEnum::ELITE_HUB->value, $channels);

        $integrated = $this->rowOf($response, $this->integratedVendor);
        $this->assertSame('single', $integrated['wallet_mode']);
        $this->assertSame(VendorChannelEnum::ELITE_HUB->value, $integrated['channel']);
        $this->assertTrue($integrated['has_game_list']);
        $this->assertTrue($integrated['callback_applicable']);
        $this->assertSame('/external/api/sw/tthsw', $integrated['default_callback_path']);
        $this->assertSame('/external/api/sw/tthsw', $integrated['callback_path']);
        $this->assertSame('https://external.test/external/api/sw/tthsw', $integrated['callback_url']);

        // 直連無縫錢包：適用回調但 Driver 沒宣告預設，未覆寫前沒有網址
        $direct = $this->rowOf($response, $this->directVendor);
        $this->assertSame('single', $direct['wallet_mode']);
        $this->assertSame('direct', $direct['channel']);
        $this->assertFalse($direct['has_game_list']);
        $this->assertTrue($direct['callback_applicable']);
        $this->assertNull($direct['default_callback_path']);
        $this->assertNull($direct['callback_path']);
        $this->assertNull($direct['callback_url']);

        // 轉帳錢包：回調一律不適用
        $transfer = $this->rowOf($response, $this->transferVendor);
        $this->assertSame('transfer', $transfer['wallet_mode']);
        $this->assertSame('direct', $transfer['channel']);
        $this->assertFalse($transfer['callback_applicable']);
        $this->assertNull($transfer['default_callback_path']);
        $this->assertNull($transfer['callback_path']);
        $this->assertNull($transfer['callback_url']);

        // config 沒掛 Driver：三項皆為 null，即使是無縫錢包也不適用回調
        $unmapped = $this->rowOf($response, $this->unmappedVendor);
        $this->assertNull($unmapped['channel']);
        $this->assertNull($unmapped['has_game_list']);
        $this->assertFalse($unmapped['callback_applicable']);
        $this->assertNull($unmapped['default_callback_path']);
        $this->assertNull($unmapped['callback_path']);
        $this->assertNull($unmapped['callback_url']);
    }

    #[TestDox('更新回調後綴：覆寫後列表顯示覆寫值，傳空字串退回 Driver 預設')]
    #[Group('rules/vendor-and-channel-management#R-13')]
    #[Group('rules/vendor-and-channel-management#R-16')]
    #[Group('rules/vendor-and-channel-management#R-9')]
    public function test_update_callback_path_overrides_and_resets(): void
    {
        $this->promoteToAdmin();
        $this->grantMenu($this->agent);

        $this->updateCallbackPath($this->integratedVendor, '/external/api/sw/custom')
            ->assertJsonPath('code', 200);

        $this->assertSame($this->agent->id, (int) $this->integratedVendor->refresh()->admin);

        $row = $this->rowOf($this->list(), $this->integratedVendor);
        $this->assertSame('/external/api/sw/custom', $row['callback_path']);
        $this->assertSame('/external/api/sw/tthsw', $row['default_callback_path']);
        $this->assertSame('https://external.test/external/api/sw/custom', $row['callback_url']);

        $this->updateCallbackPath($this->integratedVendor, '')
            ->assertJsonPath('code', 200);

        $this->assertNull($this->integratedVendor->refresh()->callback_path);

        $row = $this->rowOf($this->list(), $this->integratedVendor);
        $this->assertSame('/external/api/sw/tthsw', $row['callback_path']);
        $this->assertSame('https://external.test/external/api/sw/tthsw', $row['callback_url']);
    }

    #[TestDox('更新回調後綴：只傳 id 不帶 callback_path 被驗證擋下，既有覆寫不被清掉')]
    #[Group('rules/vendor-and-channel-management#R-13')]
    public function test_update_callback_path_requires_callback_path_key(): void
    {
        $this->promoteToAdmin();
        $this->grantMenu($this->agent);

        $this->integratedVendor->forceFill(['callback_path' => '/external/api/sw/custom'])->save();

        $this->requestApi('agent/api/vendor-management/callback-path', [
            'id' => $this->integratedVendor->id,
        ])->assertJsonPath('code', 400);

        $this->assertSame('/external/api/sw/custom', $this->integratedVendor->refresh()->callback_path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCallbackPaths(): array
    {
        return [
            '不以 / 開頭' => ['external/api/sw/custom'],
            '上層目錄' => ['/a/../b'],
            'query' => ['/a?x=1'],
            'fragment' => ['/a#f'],
            '空段' => ['//a'],
            '百分比編碼' => ['/a%20b'],
            '反斜線' => ['/a\\b'],
            '點' => ['/a.b'],
            '結尾斜線' => ['/a/'],
        ];
    }

    #[TestDox('更新回調後綴：不合格式的後綴被驗證擋下且不寫入（$path）')]
    #[DataProvider('invalidCallbackPaths')]
    #[Group('rules/vendor-and-channel-management#R-14')]
    public function test_update_callback_path_rejects_invalid_format(string $path): void
    {
        $this->promoteToAdmin();
        $this->grantMenu($this->agent);

        $this->updateCallbackPath($this->integratedVendor, $path)
            ->assertJsonPath('code', 400);

        $this->assertNull($this->integratedVendor->refresh()->callback_path);
    }

    #[TestDox('更新回調後綴：多段英數／底線／連字號的後綴可寫入')]
    #[Group('rules/vendor-and-channel-management#R-14')]
    public function test_update_callback_path_accepts_multi_segment_path(): void
    {
        $this->promoteToAdmin();
        $this->grantMenu($this->agent);

        $this->updateCallbackPath($this->integratedVendor, '/external/api/sw/kiss/h5')
            ->assertJsonPath('code', 200);

        $this->assertSame('/external/api/sw/kiss/h5', $this->integratedVendor->refresh()->callback_path);
    }

    #[TestDox('更新回調後綴：轉帳錢包不適用，請求被拒且不寫入')]
    #[Group('rules/vendor-and-channel-management#R-15')]
    public function test_update_callback_path_rejects_transfer_wallet(): void
    {
        $this->promoteToAdmin();
        $this->grantMenu($this->agent);

        $response = $this->updateCallbackPath($this->transferVendor, '/external/api/sw/custom');

        $this->assertNotSame(200, $response->json('code'));
        $this->assertNull($this->transferVendor->refresh()->callback_path);
    }

    #[TestDox('列表：非 admin 角色有選單權限仍可查，但不回任何回調相關欄位')]
    #[Group('rules/vendor-and-channel-management#R-11')]
    public function test_non_admin_list_hides_callback_fields(): void
    {
        $this->grantMenu($this->agent);

        $response = $this->list();

        $response->assertJsonPath('code', 200)
            ->assertJsonPath('data.callback_visible', false)
            ->assertJsonMissingPath('data.callback_base_url');

        $integrated = $this->rowOf($response, $this->integratedVendor);
        foreach (['callback_applicable', 'default_callback_path', 'callback_path', 'callback_url'] as $key) {
            $this->assertArrayNotHasKey($key, $integrated);
        }
        $this->assertSame(VendorChannelEnum::ELITE_HUB->value, $integrated['channel']);
        $this->assertTrue($integrated['has_game_list']);
        $this->assertSame('single', $integrated['wallet_mode']);
    }

    #[TestDox('更新回調後綴：非 admin 角色回權限不足且不寫入')]
    #[Group('rules/vendor-and-channel-management#R-12')]
    public function test_non_admin_cannot_update_callback_path(): void
    {
        $this->grantMenu($this->agent);

        $this->updateCallbackPath($this->integratedVendor, '/external/api/sw/custom')
            ->assertJsonPath('message', __('error_message.9003'));

        $this->assertNull($this->integratedVendor->refresh()->callback_path);
    }

    /** 經 EliteHub 接入、有 gamelist API、宣告回調後綴的 driver */
    private function integratedDriverClass(): string
    {
        return get_class(new class implements HasAggregatorChannel, HasCallbackPath, HasGameList
        {
            public static function aggregatorChannel(): VendorChannelEnum
            {
                return VendorChannelEnum::ELITE_HUB;
            }

            public static function defaultCallbackPath(): string
            {
                return '/external/api/sw/tthsw';
            }

            public function getGameList(GetGameListParamsData $params): GetGameListResultData
            {
                return new GetGameListResultData(gameList: []);
            }
        });
    }

    /** 什麼契約都沒實作的直連 driver */
    private function plainDriverClass(): string
    {
        return get_class(new class {});
    }
}
