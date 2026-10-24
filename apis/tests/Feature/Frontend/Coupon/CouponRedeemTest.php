<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Coupon;

use App\Enums\CouponBatchTypeEnum;
use App\Enums\General\HttpCode;
use App\Enums\RollingChipLogTypeEnum;
use App\Enums\WalletEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\CouponBatch;
use App\Models\CouponCode;
use App\Models\Member;
use App\Models\MemberWallet;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Coupon/CouponRedeemTest.php
 */
class CouponRedeemTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_COUPON_REDEEM = '/frontend/api/coupon/redeem';

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

    public function test_member_vendors_success_rolling_chip()
    {
        // Arrange
        $this->member->balance = 0;
        $this->member->save();

        $batch = CouponBatch::create([
            'batch_code' => Str::random(15),
            'agent_id' => $this->agent->id,
            'type' => CouponBatchTypeEnum::ROLLING_CHIP->value,
            'amount' => 100,
            'receive_limit' => 10,
            'quantity' => 10,
            'status' => 1,
            'start_time' => now()->format('Y-m-d H:i:s'),
            'end_time' => now()->addDays(1)->format('Y-m-d H:i:s'),
        ]);

        $codes = CouponCode::factory(10)->create([
            'batch_id' => $batch->id,
        ]);

        // Act
        $response = $this->requestApi(self::API_COUPON_REDEEM, [
            'code' => $codes->first()->code,
        ]);
        $rollingChipWallet = MemberWallet::where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_ROLLING_CHIP->value)
            ->first();

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $response->assertJson([
            'code' => HttpCode::HTTP_OK->value,
            'data' => [
                'amount' => 100,
                'type' => CouponBatchTypeEnum::ROLLING_CHIP->value,
            ],
        ]);

        $this->assertEquals(100, $rollingChipWallet->balance);
        $this->assertDatabaseHas('rolling_chip_logs', [
            'member_id' => $this->member->id,
            'type' => RollingChipLogTypeEnum::COUPON_CODE->value,
            'amount' => 100,
        ]);
    }

    public function test_member_vendors_failed_wrong_coupon_code()
    {
        // Arrange
        $this->member->balance = 0;
        $this->member->save();

        $batch = CouponBatch::create([
            'batch_code' => Str::random(15),
            'agent_id' => $this->agent->id,
            'type' => CouponBatchTypeEnum::ROLLING_CHIP->value,
            'amount' => 100,
            'receive_limit' => 10,
            'quantity' => 10,
            'status' => 1,
            'start_time' => now()->format('Y-m-d H:i:s'),
            'end_time' => now()->addDays(1)->format('Y-m-d H:i:s'),
        ]);

        $codes = CouponCode::factory(10)->create([
            'batch_id' => $batch->id,
        ]);

        // Act
        $response = $this->requestApi(self::API_COUPON_REDEEM, [
            'code' => 'not found',
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value);
        $response->assertJson([
            'code' => HttpCode::HTTP_BAD_REQUEST->value,
            'message' => __('error_message.1982'),
        ]);

        $this->assertDatabaseMissing('rolling_chip_logs', [
            'member_id' => $this->member->id,
            'type' => RollingChipLogTypeEnum::COUPON_CODE->value,
            'amount' => 100,
        ]);
    }

    public function test_member_vendors_failed_coupon_code_used()
    {
        // Arrange
        $this->member->balance = 0;
        $this->member->save();

        $batch = CouponBatch::create([
            'batch_code' => Str::random(15),
            'agent_id' => $this->agent->id,
            'type' => CouponBatchTypeEnum::ROLLING_CHIP->value,
            'amount' => 100,
            'receive_limit' => 10,
            'quantity' => 10,
            'status' => 1,
            'start_time' => now()->format('Y-m-d H:i:s'),
            'end_time' => now()->addDays(1)->format('Y-m-d H:i:s'),
        ]);

        $codes = CouponCode::factory(10)->create([
            'batch_id' => $batch->id,
        ]);

        $codes->first()->update([
            'member_id' => $this->member->id,
            'used_at' => now()->format('Y-m-d H:i:s'),
        ]);

        // Act
        $response = $this->requestApi(self::API_COUPON_REDEEM, [
            'code' => $codes->first()->code,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value);
        $response->assertJson([
            'code' => HttpCode::HTTP_BAD_REQUEST->value,
            'message' => __('error_message.1982'),
        ]);

        $this->assertDatabaseMissing('rolling_chip_logs', [
            'member_id' => $this->member->id,
            'type' => RollingChipLogTypeEnum::COUPON_CODE->value,
            'amount' => 100,
        ]);
    }

    public function test_member_vendors_failed_batch_receive_limit_reached()
    {
        // Arrange
        $this->member->balance = 0;
        $this->member->save();

        $batch = CouponBatch::create([
            'batch_code' => Str::random(15),
            'agent_id' => $this->agent->id,
            'type' => CouponBatchTypeEnum::ROLLING_CHIP->value,
            'amount' => 100,
            'receive_limit' => 1,
            'quantity' => 10,
            'status' => 1,
            'start_time' => now()->format('Y-m-d H:i:s'),
            'end_time' => now()->addDays(1)->format('Y-m-d H:i:s'),
        ]);

        $codes = CouponCode::factory(10)->create([
            'batch_id' => $batch->id,
        ]);

        $codes->first()->update([
            'member_id' => $this->member->id,
            'used_at' => now()->format('Y-m-d H:i:s'),
        ]);

        // Act
        $response = $this->requestApi(self::API_COUPON_REDEEM, [
            'code' => $codes->last()->code,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value);
        $response->assertJson([
            'code' => HttpCode::HTTP_BAD_REQUEST->value,
            'message' => __('error_message.1985'),
        ]);

        $this->assertDatabaseMissing('rolling_chip_logs', [
            'member_id' => $this->member->id,
            'type' => RollingChipLogTypeEnum::COUPON_CODE->value,
            'amount' => 100,
        ]);
    }
}
