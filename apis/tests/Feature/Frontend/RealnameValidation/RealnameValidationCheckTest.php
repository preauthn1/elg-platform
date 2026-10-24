<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\RealnameValidation;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\RealnameValidationRequest;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class RealnameValidationCheckTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_REALNAME_VALIDATION_CHECK = '/frontend/api/realname-validation/check';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->withoutMiddleware(DecryptRequest::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMemberAndToken($this->agent);

        $this->agent->vendor_customize_group_id = 1;
        $this->agent->save();
    }

    public function test_realname_validation_set_success_exists()
    {
        RealnameValidationRequest::create([
            'member_id' => $this->member->id,
            'realname' => 'test',
            'bank_name' => 'test',
            'bank_account' => 'test',
            'bank_id' => 1,
            'status' => 0,
        ]);

        $response = $this->requestApi(self::API_REALNAME_VALIDATION_CHECK, []);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'data' => [
                    'is_exists' => true,
                ],
            ]);
    }

    public function test_realname_validation_set_success_not_exists()
    {
        $response = $this->requestApi(self::API_REALNAME_VALIDATION_CHECK, []);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'data' => [
                    'is_exists' => false,
                ],
            ]);
    }
}
