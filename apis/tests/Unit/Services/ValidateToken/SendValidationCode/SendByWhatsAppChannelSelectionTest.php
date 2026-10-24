<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ValidateToken\SendValidationCode;

use App\Models\SmsGateway;
use App\Models\SmsGatewayInfo;
use App\Models\SmsText;
use App\Services\ValidateToken\SendValidationCode\WithMemberIdService;
use Database\Seeders\CountrySeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 驗證 `send_by_whatsapp` 能正確篩到 WhatsApp 管道的簡訊商，而不是一般簡訊商
 *
 * php artisan test tests/Unit/Services/ValidateToken/SendValidationCode/SendByWhatsAppChannelSelectionTest.php
 */
class SendByWhatsAppChannelSelectionTest extends TestCase
{
    private const string MOBILE = '60123456789';

    private const int COUNTRY_ID = 1; // Malaysia, country_code=60 @see CountrySeeder

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountrySeeder::class);
    }

    public function test_send_by_whatsapp_true_only_hits_whatsapp_gateway(): void
    {
        [$agent, $member] = $this->setupMemberWithBothChannels();

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertSame('whatsapp', $data['type']);

            return Http::response(['message' => 'Message sent successfully.'], 200, ['Content-Type' => 'application/json']);
        });

        app(WithMemberIdService::class)->handle([
            'type' => 11, // VERIFY_CODE
            'verify_by' => 1,
            'mobile' => self::MOBILE,
            'send_by_whatsapp' => true,
        ], $member);

        Http::assertSentCount(1);
    }

    public function test_send_by_whatsapp_false_hits_sms_gateway(): void
    {
        [$agent, $member] = $this->setupMemberWithBothChannels();

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertSame('sms', $data['type']);

            return Http::response(['message' => 'Message sent successfully.'], 200, ['Content-Type' => 'application/json']);
        });

        app(WithMemberIdService::class)->handle([
            'type' => 11,
            'verify_by' => 1,
            'mobile' => self::MOBILE,
            'send_by_whatsapp' => false,
        ], $member);

        Http::assertSentCount(1);
    }

    private function setupMemberWithBothChannels(): array
    {
        $agent = $this->createAgentAndDomain();
        $agent->sms_gateway_group_id = $agent->id + 1000;
        $agent->save();

        $member = $this->createMember($agent, [
            'mobile' => self::MOBILE,
            'mobile_validate' => 1,
        ]);

        $smsGateway = SmsGateway::create([
            'name' => 'Growixv2Test',
            'code' => 'growixv2',
            'info' => '{}',
            'url' => 'https://api.otp.expert',
            'memo' => '',
            'status' => 1,
            'admin' => 0,
        ]);

        $whatsAppGateway = SmsGateway::create([
            'name' => 'Growixv2waTest',
            'code' => 'growixv2wa',
            'info' => '{}',
            'url' => 'https://api.otp.expert',
            'memo' => '',
            'status' => 1,
            'admin' => 0,
        ]);

        foreach ([$smsGateway, $whatsAppGateway] as $gateway) {
            $gatewayInfo = SmsGatewayInfo::create([
                'sms_gateway_group_id' => $agent->sms_gateway_group_id,
                'sms_gateway_id' => $gateway->id,
                'info' => ['ApiKey' => 'test-api-key'],
                'cost' => 0,
                'status' => 1,
                'admin' => 0,
            ]);

            $gatewayInfo->smsGatewayInfoCountries()->create([
                'country_id' => self::COUNTRY_ID,
                'status' => 1,
            ]);
        }

        SmsText::create([
            'agent_id' => $agent->id,
            'sms_gateway_group_id' => $agent->sms_gateway_group_id,
            'type_id' => 11,
            'country_id' => self::COUNTRY_ID,
            'text' => 'Your code is {code}',
            'status' => 1,
            'admin' => 0,
        ]);

        return [$agent, $member];
    }
}
