<?php

declare(strict_types=1);

namespace App\Services\Frontend\ValidateToken;

use App\Enums\BindReward\BindRewardTypeEnum;
use App\Enums\MemberBindingLogTypeEnum;
use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\WalletEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\BindReward;
use App\Models\BindRewardDetail;
use App\Models\FlowLimit;
use App\Models\FlowLimitRestriction;
use App\Models\Member;
use App\Models\MemberBindingLog;
use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use App\Services\RollingChipService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MobileBindService
{
    private $nowDateTime;

    /** @var Member */
    private $member;

    public function __construct(Member $member)
    {
        $this->member = $member;
        if ($this->member->relationLoaded('agent') === false) {
            $this->member->load('agent');
        }
    }

    public function updateMemberAndGiveReward(string $phoneNumber): ?array
    {
        $this->nowDateTime = now()->format('Y-m-d H:i:s');

        try {
            DB::beginTransaction();

            $update_member = $this->member->update([
                'mobile_validate' => 1,
                'mobile' => $phoneNumber,
            ]);
            if (!$update_member) {
                return [
                    'code' => 2001,
                    'message' => __('error_message.2001'),
                ];
            }

            MemberBindingLog::create([
                'type' => MemberBindingLogTypeEnum::BINDING_PHONE->value,
                'member_id' => $this->member->id,
                'value' => $phoneNumber,
                'ip' => Common::getIp(),
                'validate_type' => 1,
                'status' => 1,
            ]);

            $this->giveRewardAndSetFlowLimit($phoneNumber);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'code' => 2001,
                'message' => $e->getMessage(),
            ];
        }

        return null;
    }

    private function giveRewardAndSetFlowLimit(string $phoneNumber)
    {
        if (empty($phoneNumber)) {
            return;
        }

        $bindRewardDetail = BindRewardDetail::lockForUpdate()
            ->where('phone', $phoneNumber)
            ->where('status', 0)
            ->first();
        if (!$bindRewardDetail) {
            return;
        }

        $bindReward = $bindRewardDetail->bindReward;
        if (!$bindReward || !$bindReward->status || strtotime($bindReward->start_time) > strtotime($this->nowDateTime)) {
            return;
        } elseif ($bindReward->end_time < $this->nowDateTime) {
            $bindReward->update([
                'status' => 0,
            ]);

            return;
        }

        if (BindRewardDetail::where('member_id', $this->member->id)->exists()) {
            // member already bind other reward
            return;
        }

        $agentHierarchyPath = $this->getAgentHierarchyPath($this->member->parent);
        if (!in_array($bindReward->agent_id, $agentHierarchyPath)) {
            // member not belongs to bindReward's agent
            return;
        }

        switch ($bindReward->type) {
            case BindRewardTypeEnum::TYPE_BALANCE:
                $flowLimits = $this->giveBalanceReward($bindReward);
                $this->setFlowLimit($flowLimits);
                break;
            case BindRewardTypeEnum::TYPE_ROLLING_CHIPS:
                // 外部 DB 數值進入時正規化至固定精度
                $point = Common::numAdd('0', (string) $bindReward->point, 4);
                (new RollingChipService($this->member))->add(
                    amount: $point,
                    type: RollingChipLogTypeEnum::BIND_MOBILE_PHONE_REWARD
                );
                break;
            default:
                throw new \Exception('Invalid bind reward type, id: ' . $bindReward->id);
        }

        $bindRewardDetail->update([
            'status' => 1,
            'member_id' => $this->member->id,
            'ip' => Common::getIp(),
            'bind_time' => $this->nowDateTime,
        ]);
    }

    private function getAgentHierarchyPath(int $agentId): array
    {
        $agent = Agent::findOrFail($agentId);

        return explode('/', substr($agent->hierarchy_path, 1));
    }

    private function giveBalanceReward(BindReward $bindReward): BindRewardFlowLimitDTO
    {
        $flowLimits = BindRewardFlowLimitDTO::fromJson($bindReward->flow_limits);
        $flowLimits->setPoint(Common::numMul($bindReward->point, $flowLimits->flowLimitMultiple));

        $isMultipleWallet = $this->member->agent->is_multi_wallet;

        if ($isMultipleWallet && Common::numCompare($flowLimits->flowLimitMultiple, '0') > 0) {
            $id = $this->giveGameWalletReward($bindReward);
            $flowLimits->walletTransactionLogId = $id;
        } else {
            $id = $this->giveMemberReward($bindReward);
            $flowLimits->transactionLogId = $id;
        }

        return $flowLimits;
    }

    private function giveGameWalletReward(BindReward $bindReward): int
    {
        // type=2 已廢除（決議⑬）只查不建；本分支僅多錢包（死路徑）可達，查無存量即顯性中止。
        // 外層 catch 會把例外訊息原文回給 API 呼叫端，故細節落 log、throw 只帶中性訊息。
        $wallet = app(WalletService::class)->getLockedWallet($this->member->id, WalletEnum::CODE_GAME);
        if (is_null($wallet)) {
            Log::error('綁定送禮中止：type=2 遊戲錢包已廢除且查無存量', ['member_id' => $this->member->id]);
            throw new \LogicException('Game wallet unavailable');
        }

        $beforeBalance = $wallet->balance;
        $afterBalance = Common::numAdd($beforeBalance, $bindReward->point);
        $wallet->update([
            'balance' => $afterBalance,
        ]);

        $logResult = WalletTransactionLog::create([
            'transaction_no' => Common::getTransactionNo(true),
            'wallet_type_id' => WalletEnum::CODE_GAME,
            'agent_id' => $this->member->parent,
            'member_id' => $this->member->id,
            'point' => $bindReward->point,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'type' => TransactionEnum::BIND_MOBILE_PHONE_GIFT->value,
            'remark' => 'System bind mobile phone reward',
            'checksum' => '',
            'result' => '',
            'operator' => 'system',
            'status' => TransactionStatusEnum::STATUS_SUCCESS,
            'admin' => 0,
        ]);

        return $logResult->id;
    }

    private function giveMemberReward(BindReward $bindReward): int
    {
        $wallet = Member::lockForUpdate()->where('id', $this->member->id)->first();
        if (!is_object($wallet)) {
            throw new \Exception('Member not found');
        }

        $beforeBalance = $wallet->balance;
        $afterBalance = Common::numAdd($beforeBalance, $bindReward->point);
        $wallet->update([
            'balance' => $afterBalance,
        ]);

        $logResult = TransactionLog::create([
            'transaction_no' => Common::getTransactionNo(),
            'agent_id' => $this->member->parent,
            'member_id' => $this->member->id,
            'point' => $bindReward->point,
            'vendor_point' => 0,
            'rate' => 0,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'type' => TransactionEnum::BIND_MOBILE_PHONE_GIFT->value,
            'operator' => 'system',
            'remark' => 'System bind mobile phone reward',
            'status' => TransactionStatusEnum::STATUS_SUCCESS,
            'admin' => 0,
        ]);

        return $logResult->id;
    }

    private function setFlowLimit(BindRewardFlowLimitDTO $flowLimits): void
    {
        if (empty($flowLimits->transactionLogId) && empty($flowLimits->walletTransactionLogId)) {
            throw new \Exception(
                'Invalid flow limit dto, both transaction log id and wallet transaction log id are empty'
            );
        }

        $result = FlowLimit::create([
            'member_id' => $this->member->id,
            'type' => TransactionEnum::BIND_MOBILE_PHONE_GIFT->value,
            'event_id' => 0,
            'reference_id' => $flowLimits->transactionLogId,
            'wallet_transaction_log_id' => $flowLimits->walletTransactionLogId,
            'flow_limit' => $flowLimits->getPoint(),
            'admin' => 1,
        ]);

        $restrictions = [];
        foreach ($flowLimits->vendorIdRestriction as $index => $vendorId) {
            $restrictions[] = [
                'flow_limit_id' => $result->id,
                'vendor_id' => $vendorId,
                'type_id' => $flowLimits->gameTypeIdRestriction[$index],
            ];
        }
        FlowLimitRestriction::insert($restrictions);
    }
}

class BindRewardFlowLimitDTO
{
    private string $point;

    /** @var int */
    public $flowLimitMultiple;

    /** @var array */
    public $vendorIdRestriction;

    /** @var array */
    public $gameTypeIdRestriction;

    /** @var ?int */
    public $transactionLogId = 0;

    /** @var ?int */
    public $walletTransactionLogId = 0;

    public function __construct(array $flowLimits)
    {
        $this->flowLimitMultiple = $flowLimits['flow_limit_multiple'] ?? 0;
        $this->vendorIdRestriction = $flowLimits['vendor_id_restriction'] ?? [0];
        $this->gameTypeIdRestriction = $flowLimits['game_type_id_restriction'] ?? [0];
    }

    public static function fromJson(string $json): self
    {
        return new self(json_decode($json, true));
    }

    public function getPoint(): string
    {
        return $this->point;
    }

    public function setPoint(int|float|string $point): void
    {
        // 正規化至固定精度，統一為 string
        $this->point = Common::numAdd('0', (string) $point, 4);
    }
}
