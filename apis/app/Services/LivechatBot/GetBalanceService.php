<?php

declare(strict_types=1);

namespace App\Services\LivechatBot;

use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Services\WalletService;

class GetBalanceService
{
    public function __construct(
        protected WalletService $walletService,
        protected ProviderWalletBalanceResolver $providerWalletBalanceResolver,
        protected LivechatBotContext $context,
        protected MemberAgentGuard $guard,
    ) {}

    /**
     * 查詢會員餘額
     *
     * @return array<string, mixed>
     */
    public function getBalance(string $username): array
    {
        $member = $this->context->findMemberByUsername($username);

        if (!$member) {
            throw new ApiException('Member not found', 404);
        }

        // 取得所有錢包
        $walletData = $this->walletService->getMemberAllWallets($member);

        $isMultiWallet = $walletData->isMultipleWallet;

        // 主錢包餘額
        $mainWalletBalance = Common::numAdd('0', (string) $walletData->mainWallet->balance, 2);

        // 遊戲錢包餘額（多錢包時使用）
        $gameWalletBalance = Common::numAdd('0', (string) ($walletData->gameWallet?->balance ?? 0), 2);

        // 禮碼餘額
        $rollingChips = Common::numAdd('0', (string) $walletData->rollingChipWallet->balance, 2);

        // 活動錢包餘額 (CODE_EVENT_WALLET)
        $eventWalletBalance = $this->getEventWalletBalance($member);

        // 遊戲商即時餘額
        $sumProviderWalletBalance = $this->providerWalletBalanceResolver->resolve($member);

        // 計算 total_wallet_balance
        $totalWalletBalance = Common::numAdd($mainWalletBalance, $sumProviderWalletBalance, 2);
        if ($isMultiWallet) {
            $totalWalletBalance = Common::numAdd($totalWalletBalance, $gameWalletBalance, 2);
        }

        // 是否參與活動
        $inPromo = $member->is_event_wallet === true;

        // 組裝回應
        $data = [
            'total_wallet_balance' => $totalWalletBalance,
            'main_wallet_balance' => $mainWalletBalance,
            'sum_provider_wallet_balance' => $sumProviderWalletBalance,
            'rolling_chips' => $rollingChips,
            'event_wallet_balance' => $eventWalletBalance,
            'in_promo' => $inPromo,
        ];

        if ($isMultiWallet) {
            $data['game_wallet_balance'] = $gameWalletBalance;
        }

        $data['description'] = $this->buildDescription($member, $isMultiWallet);
        $data['message'] = $this->buildMessage(data: $data, isMultiWallet: $isMultiWallet);

        return $data;
    }

    /**
     * 取得活動錢包餘額 (CODE_EVENT_WALLET)
     */
    private function getEventWalletBalance(Member $member): string
    {
        $eventWallet = $this->walletService->queryMemberWallet($member->id, WalletEnum::CODE_EVENT_WALLET);

        $balance = $eventWallet?->balance ?? '0';

        return Common::numAdd('0', (string) $balance, 2);
    }

    /**
     * 建立描述文字
     */
    private function buildDescription(Member $member, bool $isMultiWallet): string
    {
        $walletType = $isMultiWallet ? 'Multi Wallet' : 'Single Wallet';

        return sprintf('Member ID: %d, Wallet Type: %s', $member->id, $walletType);
    }

    /**
     * 建立人類可讀訊息陣列
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function buildMessage(array $data, bool $isMultiWallet): array
    {
        $messages = [
            'Total Wallet Balance: ' . $data['total_wallet_balance'],
            'Main Wallet Balance: ' . $data['main_wallet_balance'],
            'Sum Provider Wallet Balance: ' . $data['sum_provider_wallet_balance'],
            'Rolling Chips: ' . $data['rolling_chips'],
            'Event Wallet Balance: ' . $data['event_wallet_balance'],
            'In Promo: ' . ($data['in_promo'] ? 'Yes' : 'No'),
        ];

        if ($isMultiWallet) {
            $messages[] = 'Game Wallet Balance: ' . $data['game_wallet_balance'];
        }

        return $messages;
    }
}
