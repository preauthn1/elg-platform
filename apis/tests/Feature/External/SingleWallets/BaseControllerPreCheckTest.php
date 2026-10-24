<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\IpNotAllowedException;
use App\Exceptions\MemberNotFoundException;
use App\Exceptions\SessionTokenNotFoundException;
use App\Exceptions\SignatureVerificationException;
use App\Exceptions\VendorAccountException;
use App\Exceptions\VendorNotFoundException;
use App\Http\Controllers\External\SingleWallets\BaseController;
use App\Http\Controllers\External\SingleWallets\Contracts\AllowsUnboundAccount;
use App\Http\Controllers\External\SingleWallets\Contracts\RendersCallbackErrors;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldDecodeCallbackPayload;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldResolveVendorAccount;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyIpWhitelist;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyRequestSignature;
use App\Http\Controllers\External\SingleWallets\Data\AccountIdentifier;
use App\Vendors\Models\AcewinAccount;
use App\Vendors\Models\MotivationswAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\External\SingleWallets\MotivationSW\MotivationSWTestCase;

/**
 * BaseController::callAction() 的前置檢查樣板：每個 marker interface 各一組「有掛／沒掛」對照，
 * 守住兩件事：沒掛 marker 的 controller 行為一字不變；掛了 marker 的檢查依設計順序執行並拋對應例外。
 *
 * 借用 MotivationSW 的 fixture（vendor、vendorInfo、member、已綁定的 account），假 controller 的
 * $accountClass 指向 MotivationswAccount；路由在 setUp 內動態註冊，不進 routes/。
 *
 * php artisan test tests/Feature/External/SingleWallets/BaseControllerPreCheckTest.php
 */
class BaseControllerPreCheckTest extends MotivationSWTestCase
{
    private const string PREFIX = '/__test/sw-precheck';

    protected function setUp(): void
    {
        parent::setUp();

        Route::any(self::PREFIX . '/bare/ping', [PreCheckBareController::class, 'ping']);
        Route::any(self::PREFIX . '/ip/ping', [PreCheckIpController::class, 'ping']);
        Route::any(self::PREFIX . '/resolve/ping', [PreCheckResolveController::class, 'ping']);
        Route::any(self::PREFIX . '/resolve/unbound', [PreCheckResolveController::class, 'unbound']);
        Route::any(self::PREFIX . '/resolve/by-record', [PreCheckResolveController::class, 'byRecord']);
        Route::any(self::PREFIX . '/token/ping', [PreCheckTokenController::class, 'ping']);
        Route::any(self::PREFIX . '/foreign/ping', [PreCheckForeignVendorController::class, 'ping']);
        Route::any(self::PREFIX . '/signature/ping', [PreCheckSignatureController::class, 'ping']);
        Route::any(self::PREFIX . '/decode/ping', [PreCheckDecodeController::class, 'ping']);
        Route::any(self::PREFIX . '/ip-resolve/ping', [PreCheckIpThenResolveController::class, 'ping']);
        Route::any(self::PREFIX . '/renders/ping', [PreCheckRendersErrorsController::class, 'ping']);
        Route::any(self::PREFIX . '/renders/boom', [PreCheckRendersErrorsController::class, 'boom']);
    }

    // ---------------------------------------------------------------- 沒掛 marker

    #[TestDox('沒掛任何 marker 時，帳號不存在、IP 不在白名單都照常進 action（零行為差異）')]
    public function test_bare_controller_runs_action_without_any_check(): void
    {
        $this->enableProductionWhitelist('0.0.0.0');

        $response = $this->postJson(self::PREFIX . '/bare/ping', ['account' => 'no_such_user']);

        $response->assertOk();
        $this->assertNull($response->json('member_id'));
        $this->assertNull($response->json('account'));
    }

    // ---------------------------------------------------------------- IP 白名單

    /** rules/single-wallet-callback-common-checks#R-1：正式環境且來源 IP 不在該商戶的白名單時，任何回調都被擋下，回該遊戲商的錯誤碼，不動錢包、不寫注單；白名單為空時不檢查。 */
    #[Group('rules/single-wallet-callback-common-checks#R-1')]
    #[TestDox('ShouldVerifyIpWhitelist：production 且白名單不含來源 IP 時拋 IpNotAllowedException')]
    public function test_ip_whitelist_rejects_unknown_ip(): void
    {
        $this->enableProductionWhitelist('0.0.0.0');
        $this->withoutExceptionHandling();

        $this->expectException(IpNotAllowedException::class);
        $this->expectExceptionMessage('IP 0.0.0.0 not allow');

        $this->postJson(self::PREFIX . '/ip/ping');
    }

    /** rules/single-wallet-callback-common-checks#R-1：正式環境且來源 IP 不在該商戶的白名單時，任何回調都被擋下，回該遊戲商的錯誤碼，不動錢包、不寫注單；白名單為空時不檢查。 */
    #[Group('rules/single-wallet-callback-common-checks#R-1')]
    #[TestDox('ShouldVerifyIpWhitelist：白名單含來源 IP 時放行')]
    public function test_ip_whitelist_allows_listed_ip(): void
    {
        $this->enableProductionWhitelist('0.0.0.0,0.0.0.0');

        $this->postJson(self::PREFIX . '/ip/ping')->assertOk();
    }

    /** rules/single-wallet-callback-common-checks#R-1：正式環境且來源 IP 不在該商戶的白名單時，任何回調都被擋下，回該遊戲商的錯誤碼，不動錢包、不寫注單；白名單為空時不檢查。 */
    #[Group('rules/single-wallet-callback-common-checks#R-1')]
    #[TestDox('ShouldVerifyIpWhitelist：vendors.whitelist 為空時一律放行（沿用 Common 既有規則）')]
    public function test_ip_whitelist_allows_when_whitelist_empty(): void
    {
        $this->enableProductionWhitelist('');

        $this->postJson(self::PREFIX . '/ip/ping')->assertOk();
    }

    // ---------------------------------------------------------------- 解析帳號／會員／商戶

    #[TestDox('ShouldResolveVendorAccount：已綁定帳號解析後 account、member、vendorInfo 都就位')]
    public function test_resolve_sets_account_member_and_vendor_info(): void
    {
        $response = $this->postJson(self::PREFIX . '/resolve/ping', ['account' => $this->username]);

        $response->assertOk();
        $this->assertSame($this->member->id, $response->json('member_id'));
        $this->assertSame($this->username, $response->json('account'));
        $this->assertSame($this->vendorInfo->id, $response->json('vendor_info_id'));
    }

    #[TestDox('ShouldResolveVendorAccount：accountIdentifier() 回 null 時共用層跳過，action 自己依注單上的帳號解析')]
    public function test_null_identifier_skips_resolution_and_action_resolves_itself(): void
    {
        $response = $this->postJson(self::PREFIX . '/resolve/by-record', ['record_account' => $this->username]);

        $response->assertOk();
        $this->assertTrue($response->json('untouched_before_action'), '共用層不該碰 account／member／vendorInfo');
        $this->assertSame($this->member->id, $response->json('member_id'));
        $this->assertSame($this->username, $response->json('account'));
        $this->assertSame($this->vendorInfo->id, $response->json('vendor_info_id'));
    }

    #[TestDox('ShouldResolveVendorAccount：識別子回 null 後 action 自行解析，停用帳號一樣解析成功（結算型 action 不擋停用）')]
    public function test_null_identifier_action_side_resolution_accepts_disabled_account(): void
    {
        $this->account->update(['status' => 0]);

        $response = $this->postJson(self::PREFIX . '/resolve/by-record', ['record_account' => $this->username]);

        $response->assertOk();
        $this->assertSame($this->member->id, $response->json('member_id'));
    }

    #[TestDox('ShouldResolveVendorAccount：帳號空字串拋 VendorAccountException(Username is required)')]
    public function test_resolve_rejects_empty_username(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(VendorAccountException::class);
        $this->expectExceptionMessage('Username is required');

        $this->postJson(self::PREFIX . '/resolve/ping', ['account' => '']);
    }

    /** rules/single-wallet-callback-common-checks#R-3：回調帶的玩家帳號在我方不存在、帳號所屬商戶不存在、或帳號屬於別家遊戲商的商戶時，擋下並回該遊戲商的錯誤碼，不會拿別家商戶的設定算錢。 */
    #[Group('rules/single-wallet-callback-common-checks#R-3')]
    #[TestDox('ShouldResolveVendorAccount：帳號不存在拋 VendorAccountException(Account not found)')]
    public function test_resolve_rejects_unknown_username(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(VendorAccountException::class);
        $this->expectExceptionMessage('Account not found');

        $this->postJson(self::PREFIX . '/resolve/ping', ['account' => 'no_such_user']);
    }

    /** rules/single-wallet-callback-common-checks#R-4：帳號存在但尚未綁定會員時，回調預設被擋下並回該遊戲商的錯誤碼；遊戲商宣告允許池帳號的回調（MotivationSW 的查玩家、查餘額）放行。 */
    #[Group('rules/single-wallet-callback-common-checks#R-4')]
    #[TestDox('ShouldResolveVendorAccount：未綁定帳號（member_id=0）在一般 action 拋 VendorAccountException(Account not bound)')]
    public function test_resolve_rejects_unbound_account_on_normal_action(): void
    {
        $this->createUnboundAccount('precheck_unbound');

        $this->withoutExceptionHandling();
        $this->expectException(VendorAccountException::class);
        $this->expectExceptionMessage('Account not bound to any member');

        $this->postJson(self::PREFIX . '/resolve/ping', ['account' => 'precheck_unbound']);
    }

    #[TestDox('ShouldResolveVendorAccount：會員列不存在拋 MemberNotFoundException(Member not found)')]
    public function test_resolve_rejects_missing_member(): void
    {
        $this->account->update(['member_id' => 999999999]);

        $this->withoutExceptionHandling();
        $this->expectException(MemberNotFoundException::class);
        $this->expectExceptionMessage('Member not found');

        $this->postJson(self::PREFIX . '/resolve/ping', ['account' => $this->username]);
    }

    // ---------------------------------------------------------------- 以 session_token 指認帳號

    #[TestDox('AccountIdentifier::sessionToken：以 session_token 找到帳號，account、member、vendorInfo 都就位')]
    public function test_resolve_by_session_token(): void
    {
        $this->createTokenAccount('precheck_token_user', 'precheck-token-001');

        $response = $this->postJson(self::PREFIX . '/token/ping', ['token' => 'precheck-token-001']);

        $response->assertOk();
        $this->assertSame($this->member->id, $response->json('member_id'));
        $this->assertSame('precheck_token_user', $response->json('account'));
        $this->assertSame($this->vendorInfo->id, $response->json('vendor_info_id'));
    }

    #[TestDox('AccountIdentifier 二選一：同一支 controller 沒帶 token 時改用 username 解析')]
    public function test_token_controller_falls_back_to_username(): void
    {
        $this->createTokenAccount('precheck_token_user', 'precheck-token-001');

        $response = $this->postJson(self::PREFIX . '/token/ping', ['account' => 'precheck_token_user']);

        $response->assertOk();
        $this->assertSame('precheck_token_user', $response->json('account'));
    }

    #[TestDox('AccountIdentifier::sessionToken：token 查無帳號拋 SessionTokenNotFoundException，且它是 VendorAccountException')]
    public function test_resolve_by_unknown_session_token_rejected(): void
    {
        try {
            $this->withoutExceptionHandling()
                ->postJson(self::PREFIX . '/token/ping', ['token' => 'no-such-token']);
            $this->fail('應拋出 SessionTokenNotFoundException');
        } catch (SessionTokenNotFoundException $e) {
            $this->assertInstanceOf(VendorAccountException::class, $e);
            $this->assertSame('Session token not found', $e->getMessage());
        }
    }

    #[TestDox('AccountIdentifier::sessionToken：token 空字串拋 VendorAccountException(Session token is required)')]
    public function test_resolve_by_empty_session_token_rejected(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(VendorAccountException::class);
        $this->expectExceptionMessage('Session token is required');

        $this->postJson(self::PREFIX . '/token/ping', ['token' => '']);
    }

    #[TestDox('AllowsUnboundAccount：只放行 member_id<=0；member_id 指到不存在的會員仍拋 MemberNotFoundException')]
    public function test_allows_unbound_still_rejects_dangling_member_id(): void
    {
        $this->account->update(['member_id' => 999999999]);

        $this->withoutExceptionHandling();
        $this->expectException(MemberNotFoundException::class);

        $this->postJson(self::PREFIX . '/resolve/unbound', ['account' => $this->username]);
    }

    /** rules/single-wallet-callback-common-checks#R-4：帳號存在但尚未綁定會員時，回調預設被擋下並回該遊戲商的錯誤碼；遊戲商宣告允許池帳號的回調（MotivationSW 的查玩家、查餘額）放行。 */
    #[Group('rules/single-wallet-callback-common-checks#R-4')]
    #[TestDox('AllowsUnboundAccount：清單內的 action 放行未綁定帳號，member 為 null、vendorInfo 仍就位')]
    public function test_allows_unbound_account_on_listed_action(): void
    {
        $this->createUnboundAccount('precheck_unbound');

        $response = $this->postJson(self::PREFIX . '/resolve/unbound', ['account' => 'precheck_unbound']);

        $response->assertOk();
        $this->assertNull($response->json('member_id'));
        $this->assertSame('precheck_unbound', $response->json('account'));
        $this->assertSame($this->vendorInfo->id, $response->json('vendor_info_id'));
    }

    #[TestDox('AllowsUnboundAccount：清單內的 action 仍要求帳號存在')]
    public function test_allows_unbound_still_requires_account_exists(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(VendorAccountException::class);
        $this->expectExceptionMessage('Account not found');

        $this->postJson(self::PREFIX . '/resolve/unbound', ['account' => 'no_such_user']);
    }

    // ---------------------------------------------------------------- 商戶歸屬與 status（掛了 ShouldResolveVendorAccount 就一律驗）

    /** rules/single-wallet-callback-common-checks#R-3：回調帶的玩家帳號在我方不存在、帳號所屬商戶不存在、或帳號屬於別家遊戲商的商戶時，擋下並回該遊戲商的錯誤碼，不會拿別家商戶的設定算錢。 */
    #[Group('rules/single-wallet-callback-common-checks#R-3')]
    #[TestDox('商戶歸屬：vendorInfo 不屬於本遊戲商時拋 VendorNotFoundException，不需額外 marker')]
    public function test_foreign_vendor_info_rejected(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(VendorNotFoundException::class);
        $this->expectExceptionMessage('Vendor info not found');

        $this->postJson(self::PREFIX . '/foreign/ping', ['account' => $this->username]);
    }

    /** rules/single-wallet-callback-common-checks#R-5：遊戲帳號停用或商戶停用時，查餘額照回餘額，不回錯誤；停用只在下注時擋。 */
    #[Group('rules/single-wallet-callback-common-checks#R-5')]
    #[TestDox('帳號 status=0 不在解析層擋：account、member、vendorInfo 照常就位（停用後仍要能結算）')]
    public function test_disabled_account_still_resolves(): void
    {
        $this->account->update(['status' => 0]);

        $response = $this->postJson(self::PREFIX . '/resolve/ping', ['account' => $this->username]);

        $response->assertOk();
        $this->assertSame($this->member->id, $response->json('member_id'));
        $this->assertSame($this->username, $response->json('account'));
    }

    #[TestDox('未綁定放行的 action，帳號 status=0 一樣放行')]
    public function test_disabled_unbound_account_still_resolves_on_listed_action(): void
    {
        $this->createUnboundAccount('precheck_unbound')->update(['status' => 0]);

        $response = $this->postJson(self::PREFIX . '/resolve/unbound', ['account' => 'precheck_unbound']);

        $response->assertOk();
        $this->assertSame('precheck_unbound', $response->json('account'));
    }

    /** rules/single-wallet-callback-common-checks#R-5：遊戲帳號停用或商戶停用時，查餘額照回餘額，不回錯誤；停用只在下注時擋。 */
    #[Group('rules/single-wallet-callback-common-checks#R-5')]
    #[TestDox('商戶 status=0 不在解析層擋：vendorInfo 照常就位（停用後仍要能結算）')]
    public function test_suspended_vendor_info_still_resolves(): void
    {
        $this->vendorInfo->update(['status' => 0]);

        $response = $this->postJson(self::PREFIX . '/resolve/ping', ['account' => $this->username]);

        $response->assertOk();
        $this->assertSame($this->vendorInfo->id, $response->json('vendor_info_id'));
    }

    // ---------------------------------------------------------------- 驗簽

    /** rules/single-wallet-callback-common-checks#R-2：回調的簽章或 token 與我方用商戶設定算出的值不符時，擋下並回該遊戲商的錯誤碼，不動錢包、不寫注單。 */
    #[Group('rules/single-wallet-callback-common-checks#R-2')]
    #[TestDox('ShouldVerifyRequestSignature：驗簽失敗拋 SignatureVerificationException，warning 內參數已遮罩')]
    public function test_signature_failure_throws_and_logs_masked_params(): void
    {
        Log::spy();

        $this->withoutExceptionHandling();

        try {
            $this->withHeaders(['sig' => 'bad'])
                ->postJson(self::PREFIX . '/signature/ping', [
                    'account' => $this->username,
                    'password' => 'p@ssw0rd12345678',
                ]);
            $this->fail('應拋出 SignatureVerificationException');
        } catch (SignatureVerificationException $e) {
            $this->assertSame('Invalid request', $e->getMessage());
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_ends_with($message, 'signature_verify_failed')
                    && $context['params']['password'] === '************5678'
                    && $context['params']['account'] === $this->username;
            });
    }

    /** rules/single-wallet-callback-common-checks#R-2：回調的簽章或 token 與我方用商戶設定算出的值不符時，擋下並回該遊戲商的錯誤碼，不動錢包、不寫注單。 */
    #[Group('rules/single-wallet-callback-common-checks#R-2')]
    #[TestDox('ShouldVerifyRequestSignature：驗簽通過時放行')]
    public function test_signature_pass(): void
    {
        $this->withHeaders(['sig' => 'ok'])
            ->postJson(self::PREFIX . '/signature/ping', ['account' => $this->username])
            ->assertOk();
    }

    // ---------------------------------------------------------------- 解碼與順序

    #[TestDox('ShouldDecodeCallbackPayload 在解析帳號之前執行：帳號從解開的 payload 取得')]
    public function test_decode_runs_before_resolve(): void
    {
        $payload = base64_encode(json_encode(['account' => $this->username]));

        $response = $this->postJson(self::PREFIX . '/decode/ping', ['payload' => $payload]);

        $response->assertOk();
        $this->assertSame($this->username, $response->json('account'));
    }

    #[TestDox('IP 檢查排在帳號解析之前：IP 不過時不會查帳號表')]
    public function test_ip_check_runs_before_account_lookup(): void
    {
        $this->enableProductionWhitelist('0.0.0.0');
        $this->withoutExceptionHandling();

        DB::enableQueryLog();

        try {
            $this->postJson(self::PREFIX . '/ip-resolve/ping', ['account' => $this->username]);
            $this->fail('應拋出 IpNotAllowedException');
        } catch (IpNotAllowedException) {
            // 預期
        }

        $accountTable = (new MotivationswAccount)->getTable();
        $accountQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, $accountTable));

        $this->assertCount(0, $accountQueries, 'IP 不過就不該查帳號表');
    }

    // ---------------------------------------------------------------- 例外轉回應

    #[TestDox('RendersCallbackErrors：前置檢查拋的例外交給 renderCallbackError()，不往外拋')]
    public function test_renders_pre_check_exception(): void
    {
        $this->withoutExceptionHandling();

        $response = $this->postJson(self::PREFIX . '/renders/ping', ['account' => 'no_such_user']);

        $response->assertStatus(200);
        $this->assertSame(VendorAccountException::class, $response->json('exception'));
        $this->assertSame('Account not found', $response->json('message'));
    }

    #[TestDox('RendersCallbackErrors：action 內拋的例外同樣交給 renderCallbackError()')]
    public function test_renders_action_exception(): void
    {
        $this->withoutExceptionHandling();

        $response = $this->postJson(self::PREFIX . '/renders/boom', ['account' => $this->username]);

        $response->assertStatus(200);
        $this->assertSame(\RuntimeException::class, $response->json('exception'));
        $this->assertSame('boom', $response->json('message'));
    }

    #[TestDox('沒掛 RendersCallbackErrors 時例外原樣往外拋（前面各測試的 expectException 即以此為前提）')]
    public function test_without_renderer_exception_propagates(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(VendorAccountException::class);

        $this->postJson(self::PREFIX . '/resolve/ping', ['account' => 'no_such_user']);
    }

    // ---------------------------------------------------------------- helpers

    private function enableProductionWhitelist(string $whitelist): void
    {
        config(['app.env' => 'production']);
        $this->vendor->update(['whitelist' => $whitelist]);
    }

    /**
     * token 測試用的帳號放 acewin_accounts（有 session_token 欄位），商戶仍指向 Motivation 的 vendorInfo，
     * 讓商戶歸屬檢查對 PreCheckTokenController 的 $vendorId 成立。
     */
    private function createTokenAccount(string $username, string $sessionToken): AcewinAccount
    {
        return AcewinAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $username,
            'vendor_username' => $username,
            'session_token' => $sessionToken,
            'admin' => 1,
            'member_id' => $this->member->id,
            'status' => 1,
        ]);
    }

    private function createUnboundAccount(string $username): MotivationswAccount
    {
        return MotivationswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $username,
            'admin' => 1,
            'member_id' => 0,
            'status' => 1,
        ]);
    }
}

// ---------------------------------------------------------------- 假 controller

/**
 * 沒掛任何 marker：對照組。
 */
class PreCheckBareController extends BaseController
{
    protected int $vendorId = VendorIdEnum::MOTIVATIONSW->value;

    protected string $accountClass = MotivationswAccount::class;

    protected string $actionPrefix = 'PreCheck_';

    protected string $vendorInfoOperatorField = 'token';

    public function ping(): JsonResponse
    {
        return response()->json([
            'member_id' => ($this->member ?? null)?->id,
            'account' => ($this->account ?? null)?->username,
            'vendor_info_id' => ($this->vendorInfo ?? null)?->id,
        ]);
    }

    public function unbound(): JsonResponse
    {
        return $this->ping();
    }
}

class PreCheckIpController extends PreCheckBareController implements ShouldVerifyIpWhitelist {}

class PreCheckResolveController extends PreCheckBareController implements AllowsUnboundAccount, ShouldResolveVendorAccount
{
    public function accountIdentifier(Request $request, string $method): ?AccountIdentifier
    {
        if ($method === 'byRecord') {
            return null;
        }

        return AccountIdentifier::username((string) $request->input('account', ''));
    }

    public function methodsAllowingUnboundAccount(): array
    {
        return ['unbound'];
    }

    /** 結算型 action 的形態：請求不帶帳號，action 自己依「注單上的帳號」解析，三項必驗照跑。 */
    public function byRecord(Request $request): JsonResponse
    {
        $untouchedBeforeAction = ($this->account ?? null) === null && ($this->member ?? null) === null && ($this->vendorInfo ?? null) === null;

        $this->resolveAccount(AccountIdentifier::username((string) $request->input('record_account', '')), allowUnbound: false);
        $this->resolveVendorInfo($this->account);

        return response()->json([
            'untouched_before_action' => $untouchedBeforeAction,
            'member_id' => $this->member?->id,
            'account' => $this->account?->username,
            'vendor_info_id' => $this->vendorInfo?->id,
        ]);
    }
}

/**
 * 識別子二選一：請求帶 token 就用 session_token，否則用 account（照 Acewin／Pegasus 的形態）。
 * 帳號表改用 acewin_accounts：motivationsw_accounts 沒有 session_token 欄位。
 */
class PreCheckTokenController extends PreCheckResolveController
{
    protected string $accountClass = AcewinAccount::class;

    public function accountIdentifier(Request $request, string $method): AccountIdentifier
    {
        if ($request->has('token')) {
            return AccountIdentifier::sessionToken((string) $request->input('token', ''));
        }

        return AccountIdentifier::username((string) $request->input('account', ''));
    }
}

/**
 * $vendorId 設成不存在的值：帳號解析出的 vendorInfo 必然不屬於它，用來驗商戶歸屬。
 */
class PreCheckForeignVendorController extends PreCheckResolveController
{
    protected int $vendorId = 999999;
}

class PreCheckSignatureController extends PreCheckResolveController implements ShouldVerifyRequestSignature
{
    public function isRequestSignatureValid(Request $request): bool
    {
        return $request->header('sig') === 'ok';
    }
}

class PreCheckDecodeController extends PreCheckResolveController implements ShouldDecodeCallbackPayload
{
    public function decodeCallbackPayload(Request $request): void
    {
        $decoded = json_decode(base64_decode((string) $request->input('payload', '')), true) ?: [];
        $request->merge($decoded);
    }
}

class PreCheckIpThenResolveController extends PreCheckResolveController implements ShouldVerifyIpWhitelist {}

class PreCheckRendersErrorsController extends PreCheckResolveController implements RendersCallbackErrors
{
    public function boom(): JsonResponse
    {
        throw new \RuntimeException('boom');
    }

    public function renderCallbackError(\Throwable $e): JsonResponse
    {
        return response()->json(['exception' => $e::class, 'message' => $e->getMessage()]);
    }
}
