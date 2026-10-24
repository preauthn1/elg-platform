<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\RealnameValidation;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class RealnameValidationSetTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_REALNAME_VALIDATION_SET = '/frontend/api/realname-validation';

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

    public function test_realname_validation_set_success()
    {
        $response = $this->requestApi(self::API_REALNAME_VALIDATION_SET, [
            'realname' => 'test',
            'bank_id' => 1,
            'bank_name' => 'test',
            'bank_account' => 'test',
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);
        $this->assertDatabaseHas('realname_validation_requests', [
            'member_id' => $this->member->id,
            'realname' => 'test',
            'bank_id' => 1,
            'bank_name' => 'test',
            'bank_account' => 'test',
        ]);
    }

    public function test_realname_validation_set_rejects_non_image_photo_without_field_config()
    {
        $response = $this->requestApi(self::API_REALNAME_VALIDATION_SET, [
            'realname' => 'test',
            'bank_id' => 1,
            'bank_name' => 'test',
            'bank_account' => 'test',
            'id_front_image' => 'https://example.invalid/not-a-data-uri.png',
        ]);

        $this->assertNotSame(HttpCode::HTTP_OK->value, $response->status());
        $this->assertDatabaseMissing('realname_validation_requests', [
            'member_id' => $this->member->id,
        ]);
    }

    public function test_realname_validation_set_failed_realname_already_validated()
    {
        $this->member->realname_validate = 1;
        $this->member->save();

        $response = $this->requestApi(self::API_REALNAME_VALIDATION_SET, [
            'realname' => 'test',
            'bank_id' => 1,
            'bank_name' => 'test',
            'bank_account' => 'test',
        ]);

        $response->assertStatus(HttpCode::HTTP_NOT_ACCEPTABLE->value);
        $this->assertDatabaseMissing('realname_validation_requests', [
            'member_id' => $this->member->id,
            'realname' => 'test',
            'bank_id' => 1,
            'bank_name' => 'test',
            'bank_account' => 'test',
        ]);
    }

    public function test_realname_validation_set_failed_request_already_exists()
    {
        $this->member->realname_validate = 0;
        $this->member->save();

        $response = $this->requestApi(self::API_REALNAME_VALIDATION_SET, [
            'realname' => 'test',
            'bank_id' => 1,
            'bank_name' => 'test',
            'bank_account' => 'test',
        ]);
        $response = $this->requestApi(self::API_REALNAME_VALIDATION_SET, [
            'realname' => 'test',
            'bank_id' => 1,
            'bank_name' => 'test',
            'bank_account' => 'test',
        ]);

        $response->assertStatus(HttpCode::HTTP_NOT_ACCEPTABLE->value);
        $this->assertDatabaseHas('realname_validation_requests', [ // Should has
            'member_id' => $this->member->id,
            'realname' => 'test',
            'bank_id' => 1,
            'bank_name' => 'test',
            'bank_account' => 'test',
        ]);
    }
}
