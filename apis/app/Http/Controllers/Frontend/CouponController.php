<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\CouponBatchRewardModeEnum;
use App\Enums\CouponBatchTypeEnum;
use App\Enums\FlowLimitTypeEnum;
use App\Enums\General\HttpCode;
use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\Coupon\RedeemRequest;
use App\Http\Resources\Frontend\Coupon\RedeemResultResource;
use App\Models\CouponBatchPrize;
use App\Models\CouponBatchPrizeImage;
use App\Models\CouponCode;
use App\Models\FlowLimit;
use App\Models\FlowLimitRestriction;
use App\Models\Member;
use App\Models\PrizeContent;
use App\Models\PrizeContentFlowLimit;
use App\Models\TransactionLog;
use App\Services\RollingChipService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\DB;

#[Group('Coupon (優惠券/碼)')]
class CouponController extends Controller
{
    /**
     * 預設語系
     */
    private const DEFAULT_LANGUAGE_ID = 1;

    /**
     * 兌換優惠碼
     *
     * OLD: /api/coupon/redeem
     */
    public function redeem(RedeemRequest $request)
    {
        // === 初始化基本資料 ===
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;
        $languageId = $userData->languageId;
        $mobile = $member->mobile;
        $couponCode = $request->code;

        try {
            DB::beginTransaction();

            // === 鎖定會員並驗證 ===
            $member = Member::lockForUpdate()->find($member->id);
            if (!$member) {
                throw new ApiException(__('error_message.1005')); // 會員不存在
            }

            if ($member->event_mode > 0) {
                throw new ApiException(__('error_message.1934')); // 鎖分模式中不允許領取
            }

            if ($member->is_event_wallet) {
                throw new ApiException(__('error_message.event_mode.coupon_not_allowed')); // 活動錢包使用中不允許領取
            }

            // === 查詢並鎖定優惠碼（包含批次權限驗證）===
            // ! lock coupon code, prevent multiple redeem on same coupon code at same time.
            $couponRecord = CouponCode::lockForUpdate()
                ->where('code', $couponCode)
                ->where('batch_id', '!=', 0)
                ->where('member_id', 0)              // 提前過濾：未使用
                ->whereNull('used_at')                // 提前過濾：未兌換
                ->whereHas('batch', function ($query) use ($agent) {
                    $query->where('status', 1)
                        ->whereHas('agent', function ($agentQuery) use ($agent) {
                            $agentQuery->whereRaw("(
                                (coupon_batches.all_downline != 1 AND coupon_batches.agent_id = ?) OR
                                (coupon_batches.all_downline = 1 AND ? LIKE CONCAT(agents.hierarchy_path, '/%'))
                            )", [$agent->id, $agent->hierarchy_path . '/']);
                        })
                        ->whereRaw('start_time <= NOW()')
                        ->whereRaw('end_time >= NOW()');
                })
                ->with(['batch' => function ($query) {
                    // ! lock batch, prevent multiple redeem on same batch at same time.
                    // ! Since the received number is counted from another table, so we need to lock the batch.
                    $query->lockForUpdate()
                        ->select('id', 'type', 'reward_mode', 'amount', 'flow_limit_multiple', 'receive_limit', 'mobile_daily_limit', 'realname_required', 'vendor_lock');
                    // Not check quantity, since the coupon_codes count = quantity.
                }])
                ->first();

            // 優惠碼不存在或不可用
            if (!$couponRecord) {
                throw new ApiException(__('error_message.1982'));
            }

            if (!empty($couponRecord->used_at) || $couponRecord->member_id != 0) {
                throw new ApiException(__('error_message.1983'));
            }

            // === 提取批次資訊到變數（提升可讀性）===
            $batch = $couponRecord->batch;
            $batchId = $batch->id;
            $flowLimitMultiple = (float) $batch->flow_limit_multiple;

            // === 領取條件檢查（領取上限 / 手機每日上限 / 實名）===
            $this->assertRedeemConditions($batch, $member, $mobile);

            // === 決定本次實際派發的類型與金額 ===
            // 固定金額：沿用批次設定；隨機獎勵：依權重抽出一個獎項
            $rewardImageUrl = null;
            $prizeId = null;
            if ($batch->reward_mode == CouponBatchRewardModeEnum::RANDOM->value) {
                $prize = $this->drawRandomPrize($batchId);
                $prizeId = $prize->id;
                $rewardType = (int) $prize->point_type;
                $rewardAmount = $prize->amount;
                $rewardImageUrl = $this->resolvePrizeImage($prize->id, $languageId);
                $flowLimitMultiple = (float) $prize->flow_limit_multiple;
            } else {
                $rewardType = (int) $batch->type;
                $rewardAmount = $batch->amount;
            }

            // === 標記優惠碼為已使用（記錄中獎獎項；實際派發金額/類型以錢包紀錄為準）===
            $couponRecord->update([
                'member_id' => $member->id,
                'mobile' => $mobile,
                'coupon_batch_prize_id' => $prizeId,
                'used_at' => DB::raw('NOW()'),
            ]);

            // === 根據實際派發類型派發獎勵 ===
            $this->dispatchReward($member, $rewardType, $rewardAmount, $couponCode, $flowLimitMultiple, $batchId, (bool) $batch->vendor_lock);

            DB::commit();

            return ApiResponse::success(new RedeemResultResource([
                'amount' => $rewardAmount,
                'type' => $rewardType,
                'image_url' => $rewardImageUrl,
            ]));

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 領取條件檢查：領取上限 / 手機每日上限 / 實名。任一不符即丟出例外。
     */
    private function assertRedeemConditions($batch, Member $member, $mobile): void
    {
        // 領取次數限制
        if ($batch->receive_limit > 0) {
            $receivedCount = CouponCode::where('batch_id', $batch->id)
                ->where('member_id', $member->id)
                ->count();

            if ($receivedCount >= $batch->receive_limit) {
                throw new ApiException(__('error_message.1985'));
            }
        }

        // 手機每日領取限制
        if ($batch->mobile_daily_limit > 0) {
            if (!$member->mobile_validate || !$mobile) {
                throw new ApiException(__('error_message.9007'), HttpCode::HTTP_REDIRECT_SET_MOBILE->value);
            }

            $todayStart = now()->toFrontendTime()->startOfDay()->toSystemTime()->toDateTimeString();
            $todayEnd = now()->toFrontendTime()->endOfDay()->toSystemTime()->toDateTimeString();

            $todayReceivedCount = CouponCode::where('batch_id', $batch->id)
                ->where('mobile', $mobile)
                ->whereBetween('used_at', [$todayStart, $todayEnd])
                ->count();

            if ($todayReceivedCount >= $batch->mobile_daily_limit) {
                throw new ApiException(__('error_message.1986'));
            }
        }

        // 實名認證要求
        if ($batch->realname_required && !$member->realname_validate) {
            throw new ApiException(__('error_message.2047'));
        }
    }

    /**
     * 依派發類型把獎勵入帳到對應錢包（平臺點數 / 禮碼）。
     */
    private function dispatchReward(Member $member, int $rewardType, $rewardAmount, string $couponCode, float $flowLimitMultiple, int $batchId, bool $vendorLock): void
    {
        if ($rewardType == CouponBatchTypeEnum::POINT->value) {
            // 派發平臺點數
            $beforeBalance = $member->balance;
            $afterBalance = $beforeBalance + $rewardAmount;

            $member->update(['balance' => $afterBalance]);

            // 創建交易記錄
            $transactionLog = TransactionLog::create([
                'transaction_no' => Common::getTransactionNo(),
                'agent_id' => $member->parent,
                'point' => $rewardAmount,
                'vendor_point' => 0,
                'rate' => 1,
                'before_balance' => $beforeBalance,
                'balance' => $afterBalance,
                'type' => TransactionEnum::COUPON_CODE,
                'member_id' => $member->id,
                'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                'remark' => $couponCode,
                'checksum' => '',
                'result' => '',
                'operator' => $member->id,
                'admin' => $member->id,
            ]);

            // 創建稽核流水記錄
            if ($flowLimitMultiple > 0) {
                // 只有 coupon_batches.vendor_lock 開啟且找得到對應 prize_content 時才進入鎖分模式；
                // type 必須是 105（FlowLimitTypeEnum::EVENT_LOCK_MODE），SP_calc_flow_limit 靠這個數值判斷達標時要解除 event_mode
                $prizeContentId = $vendorLock ? PrizeContent::where('coupon_batch_id', $batchId)->value('id') : null;

                if ($prizeContentId) {
                    $member->update(['event_mode' => $prizeContentId]);
                }

                $flowLimit = FlowLimit::create([
                    'member_id' => $member->id,
                    'type' => $prizeContentId ? FlowLimitTypeEnum::EVENT_LOCK_MODE : TransactionEnum::COUPON_CODE,
                    'event_id' => $prizeContentId ? -$batchId : 0,
                    'reference_id' => $transactionLog->id,
                    'flow_limit' => (string) ($rewardAmount * $flowLimitMultiple),
                    'reach' => 0,
                    'admin' => 0,
                ]);

                if ($prizeContentId) {
                    $restrictions = PrizeContentFlowLimit::where('prize_content_id', $prizeContentId)
                        ->get(['vendor_id', 'game_type_id'])
                        ->map(fn (PrizeContentFlowLimit $limit) => [
                            'flow_limit_id' => $flowLimit->id,
                            'vendor_id' => $limit->vendor_id,
                            'type_id' => $limit->game_type_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ])
                        ->all();

                    if ($restrictions !== []) {
                        FlowLimitRestriction::insert($restrictions);
                    }
                }
            }
        } elseif ($rewardType == CouponBatchTypeEnum::ROLLING_CHIP->value) {
            // 派發禮碼
            (new RollingChipService($member))->add($rewardAmount, RollingChipLogTypeEnum::COUPON_CODE, $couponCode);
        }
    }

    /**
     * 依權重從批次獎勵池中隨機抽出一個獎項。
     *
     * 權重以整數放大（最多 4 位小數）後做累積區間抽樣，避免浮點誤差。
     */
    private function drawRandomPrize(int $batchId): CouponBatchPrize
    {
        // 僅取仍有庫存的獎項並鎖定（quantity <= 0 表示無限制）
        $prizes = CouponBatchPrize::where('batch_id', $batchId)
            ->where(function ($query) {
                $query->whereColumn('awarded', '<', 'quantity')
                    ->orWhere('quantity', '<=', 0);
            })
            ->orderBy('sort')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($prizes->isEmpty()) {
            throw new ApiException(__('error_message.1982')); // 優惠碼不可用（獎項已派發完畢）
        }

        $scale = 10000;
        $weights = $prizes->map(fn (CouponBatchPrize $prize) => (int) round((float) $prize->weight * $scale));
        $totalWeight = $weights->sum();

        if ($totalWeight <= 0) {
            throw new ApiException(__('error_message.1982')); // 優惠碼不可用（權重總和為 0）
        }

        $rand = random_int(1, $totalWeight);
        $cumulative = 0;
        $picked = $prizes->last();

        foreach ($prizes as $index => $prize) {
            $cumulative += $weights[$index];
            if ($rand <= $cumulative) {
                $picked = $prize;
                break;
            }
        }

        $picked->increment('awarded');

        return $picked;
    }

    /**
     * 取得獎項在會員語系下的圖片，找不到則退回預設語系。
     */
    private function resolvePrizeImage(int $prizeId, int $languageId): ?string
    {
        $images = CouponBatchPrizeImage::where('coupon_batch_prize_id', $prizeId)
            ->whereIn('language_id', [self::DEFAULT_LANGUAGE_ID, $languageId])
            ->get();

        return $images->firstWhere('language_id', $languageId)?->image_url
            ?? $images->firstWhere('language_id', self::DEFAULT_LANGUAGE_ID)?->image_url;
    }
}
