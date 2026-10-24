<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SmsGateways\Suppliers;

use App\Data\SmsSendData;
use App\Models\Member;
use App\Models\SmsGateway;
use App\Models\SmsGatewayInfo;
use App\Services\SmsGateways\Suppliers\WuyoGateway;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Wuyo (無憂) SMS Gateway 單元測試
 *
 * 參考實際 API：POST {base}/sms/api/sendMessageMass，JSON body：userName、content、phoneList、timestamp、sign
 * 成功回應範例：{ "code":0, "message":"处理成功", "msgId": number, "smsCount": number }
 *
 * php artisan test tests/Unit/Services/SmsGateways/Suppliers/WuyoGatewayTest.php
 */
class WuyoGatewayTest extends TestCase
{
    private WuyoGateway $gateway;

    private Member $member;

    private string $baseUrl = 'http://0.0.0.0:8001';

    private const USERNAME = 'VWorldWAOTP-0211';

    private const PASSWORD = [REDACTED]';

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new WuyoGateway;
        $agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($agent, [
            'username' => 'wuyo_t_' . uniqid(),
        ]);
    }

    public function test_send_returns_success_with_transaction_id_when_code_is_zero(): void
    {
        $info = $this->makeGatewayInfo();
        $send = $this->makeSendData(
            $info,
            '60164580966',
            'OTP Verify Code: 048468'
        );

        $expectedPath = $this->baseUrl . '/sms/api/sendMessageMass';
        $apiResponse = [
            'code' => 0,
            'message' => '处理成功',
            'msgId' => 10663961,
            'smsCount' => 1,
        ];

        Http::fake(function (HttpRequest $request) use ($expectedPath, $apiResponse) {
            $this->assertSame('POST', $request->method());
            $this->assertStringEndsWith($expectedPath, (string) $request->url());
            $this->assertSame('application/json', $request->header('Content-Type')[0] ?? '');
            $this->assertSame('application/json', $request->header('Accept')[0] ?? '');

            return Http::response($apiResponse, 200, ['Content-Type' => 'application/json']);
        });

        $result = $this->gateway->send($send);

        $this->assertSame(1, $result->status);
        $this->assertSame('10663961', $result->transactionId);
        $this->assertStringContainsString('10663961', $result->response);
        $this->assertStringContainsString('处理成功', $result->response);
    }

    public function test_send_sends_json_payload_with_correct_sign(): void
    {
        $info = $this->makeGatewayInfo();
        $send = $this->makeSendData(
            $info,
            '60164580966',
            'hello'
        );

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertArrayHasKey('userName', $data);
            $this->assertArrayHasKey('content', $data);
            $this->assertArrayHasKey('phoneList', $data);
            $this->assertArrayHasKey('timestamp', $data);
            $this->assertArrayHasKey('sign', $data);
            $this->assertSame(self::USERNAME, $data['userName']);
            $this->assertSame('hello', $data['content']);
            $this->assertSame(['60164580966'], $data['phoneList']);
            $this->assertIsInt($data['timestamp']);

            $expectedSign = md5(
                self::USERNAME
                . (string) $data['timestamp']
                . md5(self::PASSWORD)
            );
            $this->assertSame($expectedSign, $data['sign']);

            return Http::response([
                'code' => 0,
                'message' => 'ok',
                'msgId' => 1,
                'smsCount' => 1,
            ], 200, ['Content-Type' => 'application/json']);
        });

        $this->gateway->send($send);
    }

    public function test_send_records_failure_status_when_code_is_not_zero(): void
    {
        $info = $this->makeGatewayInfo();
        $send = $this->makeSendData($info, '60164580966', 'x');

        Http::fake([
            $this->baseUrl . '/sms/api/sendMessageMass' => Http::response([
                'code' => 1,
                'message' => '失敗',
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $result = $this->gateway->send($send);

        $this->assertSame(2, $result->status);
        $this->assertSame('', $result->transactionId);
    }

    public function test_send_throws_api_exception_on_http_error(): void
    {
        $info = $this->makeGatewayInfo();
        $send = $this->makeSendData($info, '60164580966', 'x');

        Http::fake([
            $this->baseUrl . '/sms/api/sendMessageMass' => Http::response('bad', 500),
        ]);

        $this->expectException(RequestException::class);

        $this->gateway->send($send);
    }

    private function makeGatewayInfo(): SmsGatewayInfo
    {
        $gw = SmsGateway::create([
            'name' => 'WuyoTest',
            'code' => 'wuyo',
            'info' => '{}',
            'url' => $this->baseUrl,
            'memo' => '',
            'status' => 1,
            'admin' => 0,
        ]);

        $row = SmsGatewayInfo::create([
            'sms_gateway_group_id' => 0,
            'sms_gateway_id' => $gw->id,
            // AsArrayObject 會再 Json::encode；勿傳 json 字串否則雙重編碼導致 info 讀出為 null
            'info' => [
                'username' => self::USERNAME,
                'password' => self::PASSWORD,
            ],
            'sort' => 0,
            'cost' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        return $row->load('smsGateway');
    }

    private function makeSendData(
        SmsGatewayInfo $smsGatewayInfo,
        string $mobile,
        string $content
    ): SmsSendData {
        return SmsSendData::from([
            'member' => $this->member,
            'smsGatewayInfo' => $smsGatewayInfo,
            'mobile' => $mobile,
            'content' => $content,
        ]);
    }
}
