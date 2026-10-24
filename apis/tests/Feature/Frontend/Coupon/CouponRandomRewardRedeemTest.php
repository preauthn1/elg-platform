<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Coupon;

use App\Enums\CouponBatchRewardModeEnum;
use App\Enums\CouponBatchTypeEnum;
use App\Enums\General\HttpCode;
use App\Enums\RollingChipLogTypeEnum;
use App\Enums\WalletEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\CouponBatch;
use App\Models\CouponBatchPrize;
use App\Models\CouponBatchPrizeImage;
use App\Models\CouponCode;
use App\Models\Member;
use App\Models\MemberWallet;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Coupon/CouponRandomRewardRedeemTest.php
 */
class CouponRandomRewardRedeemTest extends TestCase
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

    private function createRandomBatch(array $prizes): CouponBatch
    {
        $batch = CouponBatch::create([
            'batch_code' => Str::random(15),
            'agent_id' => $this->agent->id,
            'type' => CouponBatchTypeEnum::POINT->value,
            'reward_mode' => CouponBatchRewardModeEnum::RANDOM->value,
            'amount' => 0,
            'receive_limit' => 10,
            'quantity' => 10,
            'status' => 1,
            'start_time' => now()->format('Y-m-d H:i:s'),
            'end_time' => now()->addDays(1)->format('Y-m-d H:i:s'),
        ]);

        $sort = 0;
        foreach ($prizes as $prize) {
            $prizeModel = CouponBatchPrize::create([
                'batch_id' => $batch->id,
                'point_type' => $prize['point_type'],
                'amount' => $prize['amount'],
                'weight' => $prize['weight'],
                'quantity' => $prize['quantity'] ?? 0,
                'flow_limit_multiple' => $prize['flow_limit_multiple'] ?? 0,
                'sort' => $sort++,
            ]);

            // images => [language_id => url]
            foreach (($prize['images'] ?? []) as $languageId => $imageUrl) {
                CouponBatchPrizeImage::create([
                    'coupon_batch_prize_id' => $prizeModel->id,
                    'language_id' => $languageId,
                    'image_url' => $imageUrl,
                ]);
            }
        }

        return $batch;
    }

    public function test_random_reward_redeem_rolling_chip_single_prize()
    {
        // Arrange：唯一獎項，必中
        $this->member->balance = 0;
        $this->member->save();

        // 圖片設在預設語系(1)，redeem 會以會員語系查、找不到時退回預設語系
        $batch = $this->createRandomBatch([
            ['point_type' => CouponBatchTypeEnum::ROLLING_CHIP->value, 'amount' => 50, 'weight' => 1, 'images' => [1 => 'https://cdn.test/prize-50.png']],
        ]);

        $codes = CouponCode::factory(10)->create(['batch_id' => $batch->id]);

        // Act
        $response = $this->requestApi(self::API_COUPON_REDEEM, [
            'code' => $codes->first()->code,
        ]);
        $rollingChipWallet = MemberWallet::where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_ROLLING_CHIP->value)
            ->first();

        // Assert：回傳中獎金額、類型與獎項圖片
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $response->assertJson([
            'code' => HttpCode::HTTP_OK->value,
            'data' => [
                'amount' => 50,
                'type' => CouponBatchTypeEnum::ROLLING_CHIP->value,
                'image_url' => 'https://cdn.test/prize-50.png',
            ],
        ]);

        $this->assertEquals(50, $rollingChipWallet->balance);
        $this->assertDatabaseHas('rolling_chip_logs', [
            'member_id' => $this->member->id,
            'type' => RollingChipLogTypeEnum::COUPON_CODE->value,
            'amount' => 50,
        ]);
        // coupon_codes 記錄中獎獎項ID（金額/類型以上方錢包紀錄為準）
        $prize = CouponBatchPrize::where('batch_id', $batch->id)->first();
        $this->assertDatabaseHas('coupon_codes', [
            'id' => $codes->first()->id,
            'member_id' => $this->member->id,
            'coupon_batch_prize_id' => $prize->id,
        ]);
    }

    public function test_random_reward_redeem_point_single_prize()
    {
        // Arrange：唯一點數獎項，必中
        $this->member->balance = 0;
        $this->member->save();

        $batch = $this->createRandomBatch([
            ['point_type' => CouponBatchTypeEnum::POINT->value, 'amount' => 30, 'weight' => 1],
        ]);

        $codes = CouponCode::factory(10)->create(['batch_id' => $batch->id]);

        // Act
        $response = $this->requestApi(self::API_COUPON_REDEEM, [
            'code' => $codes->first()->code,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $response->assertJson([
            'code' => HttpCode::HTTP_OK->value,
            'data' => [
                'amount' => 30,
                'type' => CouponBatchTypeEnum::POINT->value,
            ],
        ]);

        $this->assertEquals(30, Member::find($this->member->id)->balance);
        // coupon_codes 記錄中獎獎項ID（金額由上方 member balance 驗證）
        $prize = CouponBatchPrize::where('batch_id', $batch->id)->first();
        $this->assertDatabaseHas('coupon_codes', [
            'id' => $codes->first()->id,
            'member_id' => $this->member->id,
            'coupon_batch_prize_id' => $prize->id,
        ]);
    }

    public function test_random_reward_zero_weight_prize_is_never_drawn()
    {
        // Arrange：權重為 0 的獎項永遠不會中，必中權重 > 0 的那項
        $this->member->balance = 0;
        $this->member->save();

        $batch = $this->createRandomBatch([
            ['point_type' => CouponBatchTypeEnum::POINT->value, 'amount' => 999, 'weight' => 0],
            ['point_type' => CouponBatchTypeEnum::POINT->value, 'amount' => 5, 'weight' => 10],
        ]);

        $codes = CouponCode::factory(10)->create(['batch_id' => $batch->id]);

        // Act：同一會員兌換多張碼（receive_limit=10），重複數次確保不是運氣
        for ($i = 0; $i < 5; $i++) {
            $response = $this->requestApi(self::API_COUPON_REDEEM, [
                'code' => $codes[$i]->code,
            ]);

            // Assert：永遠抽到權重 > 0 的 5 點，不會是權重 0 的 999
            $response->assertStatus(HttpCode::HTTP_OK->value);
            $response->assertJson([
                'code' => HttpCode::HTTP_OK->value,
                'data' => [
                    'amount' => 5,
                    'type' => CouponBatchTypeEnum::POINT->value,
                ],
            ]);
        }
    }

    public function test_random_reward_prize_quantity_limit_exhausted()
    {
        // Arrange：唯一獎項僅有 1 份庫存
        $this->member->balance = 0;
        $this->member->save();

        $batch = $this->createRandomBatch([
            ['point_type' => CouponBatchTypeEnum::POINT->value, 'amount' => 88, 'weight' => 1, 'quantity' => 1],
        ]);

        $codes = CouponCode::factory(10)->create(['batch_id' => $batch->id]);

        // Act 1：第一次兌換成功，庫存用盡
        $first = $this->requestApi(self::API_COUPON_REDEEM, [
            'code' => $codes[0]->code,
        ]);

        // Act 2：第二次兌換，已無可派發獎項
        $second = $this->requestApi(self::API_COUPON_REDEEM, [
            'code' => $codes[1]->code,
        ]);

        // Assert
        $first->assertStatus(HttpCode::HTTP_OK->value);
        $first->assertJson(['data' => ['amount' => 88]]);
        $this->assertDatabaseHas('coupon_batch_prizes', [
            'batch_id' => $batch->id,
            'awarded' => 1,
        ]);

        $second->assertStatus(HttpCode::HTTP_BAD_REQUEST->value);
        $second->assertJson([
            'code' => HttpCode::HTTP_BAD_REQUEST->value,
            'message' => __('error_message.1982'),
        ]);
        // 第二張碼未被消耗（交易已回滾）
        $this->assertDatabaseHas('coupon_codes', [
            'id' => $codes[1]->id,
            'member_id' => 0,
        ]);
    }

    public function test_random_reward_prize_uses_own_flow_limit_multiple()
    {
        // Arrange：點數獎項自帶稽核流水倍數 3
        $this->member->balance = 0;
        $this->member->save();

        $batch = $this->createRandomBatch([
            ['point_type' => CouponBatchTypeEnum::POINT->value, 'amount' => 100, 'weight' => 1, 'flow_limit_multiple' => 3],
        ]);

        $codes = CouponCode::factory(10)->create(['batch_id' => $batch->id]);

        // Act
        $response = $this->requestApi(self::API_COUPON_REDEEM, [
            'code' => $codes->first()->code,
        ]);

        // Assert：稽核流水 = 金額 100 × 倍數 3 = 300
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->member->id,
            'flow_limit' => '300',
        ]);
    }
}
