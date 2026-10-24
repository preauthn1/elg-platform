<?php

declare(strict_types=1);

namespace App\Services\LivechatBot;

use App\Enums\LivechatBotWithdrawEligibilityEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Services\MemberService;
use App\Services\WalletService;

class GetWithdrawBalanceService
{
    public function __construct(
        protected WalletService $walletService,
        protected MemberService $memberService,
        protected ProviderWalletBalanceResolver $providerWalletBalanceResolver,
        protected LivechatBotContext $context,
        protected MemberAgentGuard $guard,
    ) {}

    /**
     * 查詢會員可提款餘額與資格判斷
     *
     * @return array<string, mixed>
     */
    public function getWithdrawBalance(string $username): array
    {
        $member = $this->context->findMemberByUsername($username);

        if ($member === null) {
            throw new ApiException('Member not found', 404);
        }

        $walletData = $this->walletService->getMemberAllWallets($member);
        $mainWalletBalance = Common::numAdd('0', (string) $walletData->mainWallet->balance, 2);
        $providerWalletBalance = $this->providerWalletBalanceResolver->resolve($member);

        $flowLimitData = $this->memberService->getFlowLimit($member);
        $turnoverReach = Common::numAdd('0', (string) $flowLimitData['reach'], 4);
        $turnoverRequired = Common::numAdd('0', (string) $flowLimitData['flow_limit'], 4);
        $turnoverProgress = $turnoverReach . ' / ' . $turnoverRequired;

        $eligibility = $this->resolveEligibility(
            member: $member,
            flowLimitPass: (int) $flowLimitData['pass'],
            mainWalletBalance: $mainWalletBalance,
        );

        $canWithdraw = $eligibility === LivechatBotWithdrawEligibilityEnum::ELIGIBLE;
        $withdrawable = $canWithdraw ? $mainWalletBalance : Common::numAdd('0', '0', 2);
        $nonWithdrawable = $canWithdraw
            ? Common::numAdd('0', '0', 2)
            : Common::numAdd($mainWalletBalance, $providerWalletBalance, 2);

        $output = [
            'main_wallet_balance' => $mainWalletBalance,
            'provider_wallet_balance' => $providerWalletBalance,
            'main_wallet_turnover_progress' => $turnoverProgress,
            'withdrawable_balance' => $withdrawable,
            'non_withdrawable_balance' => $nonWithdrawable,
            'withdraw_eligibility' => $eligibility->value,
        ];

        $output['message'] = $this->buildMessage($output);

        return $output;
    }

    /**
     * 判斷提款資格
     */
    private function resolveEligibility(
        Member $member,
        int $flowLimitPass,
        string $mainWalletBalance,
    ): LivechatBotWithdrawEligibilityEnum {
        return match (true) {
            $member->is_event_wallet === true => LivechatBotWithdrawEligibilityEnum::ACTIVE_PROMOTION,
            $flowLimitPass === 0 => LivechatBotWithdrawEligibilityEnum::TURNOVER_NOT_MET,
            Common::numCompare($mainWalletBalance, '0') <= 0 => LivechatBotWithdrawEligibilityEnum::INSUFFICIENT_BALANCE,
            default => LivechatBotWithdrawEligibilityEnum::ELIGIBLE,
        };
    }

    /**
     * 建立人類可讀訊息陣列
     *
     * @param  array<string, mixed>  $output
     * @return array<int, string>
     */
    private function buildMessage(array $output): array
    {
        return [
            'Main Wallet Balance: ' . $output['main_wallet_balance'],
            'Provider Wallet Balance: ' . $output['provider_wallet_balance'],
            'Main Wallet Turnover Progress: ' . $output['main_wallet_turnover_progress'],
            'Withdrawable Balance: ' . $output['withdrawable_balance'],
            'Non-Withdrawable Balance: ' . $output['non_withdrawable_balance'],
            'Withdraw Eligibility: ' . $output['withdraw_eligibility'],
        ];
    }
}
