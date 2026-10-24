<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SmsGateways\Suppliers;

use App\Data\SmsSendData;
use App\Models\Member;
use App\Models\SmsGateway;
use App\Models\SmsGatewayInfo;
use App\Services\SmsGateways\Suppliers\Growixv2Gateway;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Growix v2 SMS OTP 單元測試
 *
 * 參考實際 API：POST {base}/api/send，header X-API-KEY，JSON body：type=sms、recipient、message
 *
 * php artisan test tests/Unit/Services/SmsGateways/Suppliers/Growixv2GatewayTest.php
 */
class Growixv2GatewayTest extends TestCase
{
    private Growixv2Gateway $gateway;

    private Member $member;

    private string $baseUrl = 'https://api.otp.expert';

    private const API_KEY = [REDACTED]';

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new Growixv2Gateway;
        $agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($agent, [
            'username' => 'growixv2_t_' . uniqid(),
        ]);
    }

    public function test_send_sends_type_sms_with_e164_mobile_and_digits_only_otp(): void
    {
        $info = $this->makeGatewayInfo();
        $send = $this->makeSendData($info, '60164580966', 'OTP: 048468');

        Http::fake(function (HttpRequest $request) {
            $this->assertSame('POST', $request->method());
            $this->assertStringEndsWith($this->baseUrl . '/api/send', (string) $request->url());
            $this->assertSame(self::API_KEY, $request->header('X-API-KEY')[0] ?? '');

            $data = $request->data();
            $this->assertSame('sms', $data['type']);
            $this->assertSame('+60164580966', $data['recipient']);
            $this->assertSame('048468', $data['message']);

            return Http::response([
                'message' => 'Message sent successfully.',
                'final_cost' => 0.0175,
            ], 200, ['Content-Type' => 'application/json']);
        });

        $result = $this->gateway->send($send);

        $this->assertSame(1, $result->status);
        $this->assertStringContainsString('successfully', $result->response);
    }

    public function test_send_records_failure_status_when_message_does_not_contain_success(): void
    {
        $info = $this->makeGatewayInfo();
        $send = $this->makeSendData($info, '60164580966', 'x');

        Http::fake([
            $this->baseUrl . '/api/send' => Http::response([
                'message' => 'Invalid recipient.',
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $result = $this->gateway->send($send);

        $this->assertSame(2, $result->status);
    }

    private function makeGatewayInfo(): SmsGatewayInfo
    {
        $gw = SmsGateway::create([
            'name' => 'Growixv2Test',
            'code' => 'growixv2',
            'info' => '{}',
            'url' => $this->baseUrl,
            'memo' => '',
            'status' => 1,
            'admin' => 0,
        ]);

        $row = SmsGatewayInfo::create([
            'sms_gateway_group_id' => 0,
            'sms_gateway_id' => $gw->id,
            'info' => [
                'ApiKey' => self::API_KEY,
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
