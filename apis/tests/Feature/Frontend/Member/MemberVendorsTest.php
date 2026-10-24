<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Member;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Services\MemberService;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Mockery;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Member/MemberVendorsTest.php
 */
class MemberVendorsTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_MEMBER_TRANSFER = '/frontend/api/member/vendors';

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

    public function test_member_vendors_success()
    {
        // Arrange
        $vendor1 = $this->createBasicVendorGames($this->agent);
        $vendor2 = $this->createBasicVendorGames($this->agent);

        $mock = Mockery::mock(MemberService::class)->makePartial();
        $mock->shouldReceive('isMemberExistsInVendor')
            ->with($this->member->id, $vendor1->tname)
            ->andReturn(true);
        $this->app->instance(MemberService::class, $mock);

        // Act
        $response = $this->requestApi(self::API_MEMBER_TRANSFER, []);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $response->assertJson([
            'code' => HttpCode::HTTP_OK->value,
            'data' => [
                [
                    'vendor_id' => $vendor1->id,
                    'vendor_name' => $vendor1->name,
                ],
            ],
        ]);
    }
}
