<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThirdParty;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\ThirdParty\ThirdPartyDecryptRequest;
use App\Models\Agent;
use App\Models\LiveChatAccount;
use App\Models\Member;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThirdPartyLiveChatTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_GET_TOKEN = [REDACTED]';

    const API_GET_FRESH_TOKEN = [REDACTED]';

    const API_HAS_TOKEN = [REDACTED]';

    const API_INVALIDATE = '/frontend/api/third-party/live-chat/invalidate';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->withoutMiddleware(ThirdPartyDecryptRequest::class);

        $this->member = $this->createMemberAndToken($this->agent, [
            'username' => 'livechatmember',
        ]);
    }

    public function test_has_token_false_when_no_account()
    {
        $response = $this->requestApi(self::API_HAS_TOKEN, [
            'username' => $this->member->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson(['data' => ['hasToken' => false]]);
    }

    public function test_has_token_true_when_account_exists()
    {
        LiveChatAccount::create([
            'member_id' => $this->member->id,
            'creation_date' => now(),
            'license_id' => 1,
            'access_token' => 'token',
            'entity_id' => 'entity',
            'expires_in' => 3600,
            'token_type' => 'bearer',
            'lc_cid' => 'cid',
            'lc_cst' => 'cst',
        ]);

        $response = $this->requestApi(self::API_HAS_TOKEN, [
            'username' => $this->member->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson(['data' => ['hasToken' => true]]);
    }

    public function test_get_fresh_token_calls_livechat_oauth_and_stores_account()
    {
        Http::fake([
            'example.invalid/*' => Http::response(
                ['access_token' => 'new-access-token', 'entity_id' => 'e1', 'expires_in' => 3600, 'token_type' => 'bearer'],
                200,
                ['Set-Cookie' => ['__lc_cid=cid-value; Path=/', '__lc_cst=cst-value; Path=/']]
            ),
        ]);

        $response = $this->requestApi(self::API_GET_FRESH_TOKEN, [
            'username' => $this->member->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'data' => [
                    'accessToken' => 'new-access-token',
                    'entityId' => 'e1',
                    'expiresIn' => 3600,
                    'tokenType' => 'bearer',
                    '__lc_cid' => 'cid-value',
                    '__lc_cst' => 'cst-value',
                ],
            ]);

        $this->assertDatabaseHas('live_chat_accounts', [
            'member_id' => $this->member->id,
            'access_token' => 'new-access-token',
            'lc_cid' => 'cid-value',
            'lc_cst' => 'cst-value',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.invalid/customer/token'
                && $request['grant_type'] === 'cookie';
        });
    }

    public function test_get_fresh_token_updates_existing_account()
    {
        $existing = LiveChatAccount::create([
            'member_id' => $this->member->id,
            'creation_date' => now()->subDay(),
            'license_id' => 1,
            'access_token' => 'old-token',
            'entity_id' => 'old-entity',
            'expires_in' => 100,
            'token_type' => 'bearer',
            'lc_cid' => 'old-cid',
            'lc_cst' => 'old-cst',
        ]);

        Http::fake([
            'example.invalid/*' => Http::response(
                ['access_token' => 'refreshed-token', 'entity_id' => 'old-entity', 'expires_in' => 3600, 'token_type' => 'bearer'],
                200,
                ['Set-Cookie' => ['__lc_cid=old-cid; Path=/', '__lc_cst=new-cst; Path=/']]
            ),
        ]);

        $response = $this->requestApi(self::API_GET_FRESH_TOKEN, [
            'username' => $this->member->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertSame(1, LiveChatAccount::where('member_id', $this->member->id)->count());
        $existing->refresh();
        $this->assertSame('refreshed-token', $existing->access_token);
        $this->assertSame('new-cst', $existing->lc_cst);
    }

    public function test_get_token_returns_existing_account_without_calling_oauth()
    {
        LiveChatAccount::create([
            'member_id' => $this->member->id,
            'creation_date' => now(),
            'license_id' => 1,
            'access_token' => 'existing-token',
            'entity_id' => 'entity',
            'expires_in' => 3600,
            'token_type' => 'bearer',
            'lc_cid' => 'cid',
            'lc_cst' => 'cst',
        ]);

        Http::fake();

        $response = $this->requestApi(self::API_GET_TOKEN, [
            'username' => $this->member->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson(['data' => ['accessToken' => 'existing-token']]);

        Http::assertNothingSent();
    }

    public function test_get_token_fetches_fresh_token_when_no_existing_account()
    {
        Http::fake([
            'example.invalid/*' => Http::response(
                ['access_token' => 'brand-new-token', 'entity_id' => 'e1', 'expires_in' => 3600, 'token_type' => 'bearer'],
                200,
                ['Set-Cookie' => ['__lc_cid=cid-value; Path=/', '__lc_cst=cst-value; Path=/']]
            ),
        ]);

        $response = $this->requestApi(self::API_GET_TOKEN, [
            'username' => $this->member->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson(['data' => ['accessToken' => 'brand-new-token']]);
    }

    public function test_invalidate_deletes_account()
    {
        LiveChatAccount::create([
            'member_id' => $this->member->id,
            'creation_date' => now(),
            'license_id' => 1,
            'access_token' => 'token',
            'entity_id' => 'entity',
            'expires_in' => 3600,
            'token_type' => 'bearer',
            'lc_cid' => 'cid',
            'lc_cst' => 'cst',
        ]);

        $response = $this->requestApi(self::API_INVALIDATE, [
            'username' => $this->member->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson(['data' => ['invalidated' => true]]);

        $this->assertDatabaseMissing('live_chat_accounts', [
            'member_id' => $this->member->id,
        ]);
    }

    public function test_failed_member_not_found()
    {
        // 會員不存在時，middleware（ThirdPartyAuthMember）已先擋下，不會進到 controller
        $response = $this->requestApi(self::API_HAS_TOKEN, [
            'username' => 'no-such-member',
        ]);

        $response->assertStatus(HttpCode::HTTP_NOT_ACCEPTABLE->value)
            ->assertJson(['message' => __('error_message.1005')]);
    }
}
