<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ValidateToken\SendValidationCode;

use App\Exceptions\ApiException;
use App\Models\Member;
use App\Models\ValidateToken;
use App\Services\ValidateToken\SendValidationCode\OnePageRegisterService;
use App\Services\ValidateToken\SendValidationCode\RegisterV2SendService;
use App\Services\ValidateToken\SendValidationCode\WithMemberIdService;
use App\Services\ValidateToken\SendValidationCode\WithoutMemberIdService;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 迴歸：4 支 SendValidationCode 服務原本傳 ['value' => $maxSend]（單數鍵），
 * 但 error_message.2037 語言字串的佔位符是 :values（複數）。Laravel 的 strtr
 * 只會匹配到 :value，留下尾巴 s，實際輸出會變成「您已經點擊超過3s次」這種亂碼。
 * 改成 ['values' => $maxSend] 後才是完整替換。
 *
 * php artisan test tests/Unit/Services/ValidateToken/SendValidationCode/MaxSendMessagePlaceholderTest.php
 */
class MaxSendMessagePlaceholderTest extends TestCase
{
    private const int DEFAULT_MAX_SEND = 3;

    public function test_with_member_id_service_throws_correctly_formatted_message(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['mobile' => '0911111111', 'mobile_validate' => 1]);

        $this->seedExhaustedToken($member->id, 111, $member->mobile);

        try {
            app(WithMemberIdService::class)->handle([
                'type' => 11, // VERIFY_CODE
                'verify_by' => 1,
                'mobile' => $member->mobile,
            ], $member);
            $this->fail('Expected ApiException was not thrown');
        } catch (ApiException $e) {
            $this->assertSame(__('error_message.2037', ['values' => self::DEFAULT_MAX_SEND]), $e->getMessage());
        }
    }

    public function test_without_member_id_service_throws_correctly_formatted_message(): void
    {
        // fresh()：factory 建立的 in-memory 物件 binding_scope_id 停在 null，
        // 未套用 DB 端 default(0)；filterMembers() 用它比對 member.agent.binding_scope_id（實際存的是 0），
        // null 跟 0 在 SQL 的 `=` 比對下永遠對不上，會導致查無會員
        $agent = $this->createAgentAndDomain()->fresh();
        $member = $this->createMember($agent, ['mobile' => '0922222222', 'mobile_validate' => 1]);

        $this->seedExhaustedToken($member->id, 21, $member->mobile);

        try {
            app(WithoutMemberIdService::class)->handle([
                'type' => 2, // FORGET_PASSWORD
                'verify_by' => 1,
                'mobile' => $member->mobile,
                'email' => '',
                'domain' => $this->domain,
            ], $agent);
            $this->fail('Expected ApiException was not thrown');
        } catch (ApiException $e) {
            $this->assertSame(__('error_message.2037', ['values' => self::DEFAULT_MAX_SEND]), $e->getMessage());
        }
    }

    public function test_register_v2_send_service_throws_correctly_formatted_message(): void
    {
        $agent = $this->createAgentAndDomain();
        $fakeMember = $this->createFakeRegistrantMember($agent->id);

        $this->seedExhaustedToken($fakeMember->id, 121, '0933333333');

        try {
            app(RegisterV2SendService::class)->handle([
                'domain' => $this->domain,
                'type' => 12, // REGISTER_V2
                'verify_by' => 1,
                'mobile' => '0933333333',
            ]);
            $this->fail('Expected ApiException was not thrown');
        } catch (ApiException $e) {
            $this->assertSame(__('error_message.2037', ['values' => self::DEFAULT_MAX_SEND]), $e->getMessage());
        }
    }

    public function test_one_page_register_service_throws_correctly_formatted_message(): void
    {
        $agent = $this->createAgentAndDomain();
        $fakeMember = $this->createFakeRegistrantMember($agent->id);

        $this->seedExhaustedToken($fakeMember->id, 81, '0944444444');

        try {
            app(OnePageRegisterService::class)->handle([
                'domain' => $this->domain,
                'type' => 8, // REGISTER
                'verify_by' => 1,
                'mobile' => '0944444444',
            ]);
            $this->fail('Expected ApiException was not thrown');
        } catch (ApiException $e) {
            $this->assertSame(__('error_message.2037', ['values' => self::DEFAULT_MAX_SEND]), $e->getMessage());
        }
    }

    /**
     * 預先塞一筆已達最大發送次數（times=DEFAULT_MAX_SEND）的有效 token，
     * 讓 handle() 一進來就命中「已超過發送次數」分支，不必真的打簡訊/信件閘道。
     */
    private function seedExhaustedToken(int $memberId, int $typeId, string $info): ValidateToken
    {
        return ValidateToken:[REDACTED]
            'agent_id' => 0,
            'member_id' => $memberId,
            'type_id' => $typeId,
            'info' => $info,
            'info_2' => '',
            'token' => '000000',
            'expire_date' => now()->addMinutes(10),
            'status' => 0,
            'times' => self::DEFAULT_MAX_SEND,
            'admin' => $memberId,
        ]);
    }

    /**
     * 比照 SendValidationCodeService::createFakeMember() 的命名規則建立假會員，
     * 讓 getFakeMember('Registrants', $agentId) 直接查到這筆、不再另建一筆。
     */
    private function createFakeRegistrantMember(int $agentId): Member
    {
        return Member::create([
            'parent' => $agentId,
            'username' => 'Registrants_' . $agentId,
            'username_bak' => 'Registrants_' . $agentId,
            'nickname' => 'Registrants',
            'password' => Hash::make('DD203BC58AD6DAA5B0A2B4CD619C38C6'),
            'status' => 0,
            'admin' => 0,
        ]);
    }
}
