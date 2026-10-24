<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\DstPlaySW;

use App\Helpers\Common;

/**
 * php artisan test tests/Feature/External/SingleWallets/DstPlaySW/GetAuthTest.php --compact
 */
class GetAuthTest extends DstPlaySWTestCase
{
    public function test_get_auth_returns_balance_and_user_id_for_valid_token()
    {
        $this->member->update(['balance' => '100.0000']);

        $response = $this->postJson("{$this->baseurl}/getAuth", $this->signedPostData([
            'token' => 'test_session_token',
        ]));

        $response->assertStatus(200)->assertJson([
            'code' => 0,
            'msg' => 'Success.',
            'balance' => 100.0,
            'userId' => $this->username,
        ]);
    }

    public function test_get_auth_returns_not_found_for_invalid_token()
    {
        $response = $this->postJson("{$this->baseurl}/getAuth", $this->signedPostData([
            'token' => 'wrong_token',
        ]));

        $response->assertStatus(200)->assertJson([
            'code' => 101,
            'msg' => 'Not Found.',
        ]);
    }

    public function test_get_auth_returns_missing_parameter_when_token_absent()
    {
        $response = $this->postJson("{$this->baseurl}/getAuth", $this->signedPostData());

        $response->assertStatus(200)->assertJson([
            'code' => 104,
            'msg' => 'Missing Parameter.',
        ]);
    }

    public function test_get_auth_returns_unauthorized_for_wrong_signature()
    {
        $postData = $this->signedPostData(['token' => 'test_session_token']);
        $postData['sign'] = 'invalid_signature';

        $response = $this->postJson("{$this->baseurl}/getAuth", $postData);

        $response->assertStatus(200)->assertJson([
            'code' => 107,
            'msg' => 'Unauthorized request.',
        ]);
    }

    public function test_get_auth_returns_unauthorized_when_account_disabled()
    {
        $this->account->update(['status' => 0]);

        $response = $this->postJson("{$this->baseurl}/getAuth", $this->signedPostData([
            'token' => 'test_session_token',
        ]));

        $response->assertStatus(200)->assertJson([
            'code' => 107,
            'msg' => 'Unauthorized request.',
        ]);
    }

    /**
     * 2026-09-10 推翻原本的「API_KEY 為空即略過驗簽」：那讓設定漏填等同於無聲地
     * 拆掉這道防線，而本 vendor 的 vendors.whitelist 五站實查皆為空——缺鍵時就是
     * 兩道皆無。改動前實查五站，實際接入的只有 TPower 且 API_KEY 有值，改為拒收零影響。
     */
    public function test_get_auth_is_rejected_when_api_key_empty()
    {
        $this->vendorInfo->update(['info' => [
            'API_URL' => '',
            'API_ID' => $this->apiId,
            'API_KEY' => '',
        ]]);
        $this->member->update(['balance' => '50.0000']);

        $response = $this->postJson("{$this->baseurl}/getAuth", [
            'api_id' => $this->apiId,
            'timestamp' => (string) (int) round(microtime(true) * 1000),
            'sign' => 'anything',
            'token' => 'test_session_token',
        ]);

        $response->assertStatus(200)->assertJson([
            'code' => 107,
            'msg' => 'Unauthorized request.',
        ]);

        $this->assertSame('50.0000', Common::numAdd('0', (string) $this->member->balance, 4));
    }
}
