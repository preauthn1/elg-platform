<?php

namespace App\DTOs;

use App\Enums\MemberWalletTypeEnum;

readonly class PlatformWalletBalanceDTO
{
    public int $platformMemberId;

    public string $mainWallet;         // Decimal

    public string $gameWallet;         // Decimal

    public string $rollingChipWallet;  // Decimal

    public bool $isMultipleWallet;

    public function __construct(array $platformWalletData)
    {
        $this->platformMemberId = (int) $platformWalletData['member_id'];
        $this->isMultipleWallet = (bool) ($platformWalletData['is_multiple_wallet'] ?? false);

        $wallets = $platformWalletData['wallet'];
        foreach ($wallets as $wallet) {
            $walletType = (int) $wallet['id'];

            if ($walletType === MemberWalletTypeEnum::MAIN->value) {
                $this->mainWallet = $wallet['balance'];
            } elseif ($walletType === MemberWalletTypeEnum::GAME->value) {
                $this->gameWallet = $wallet['balance'];
            } elseif ($walletType === MemberWalletTypeEnum::ROLLING_CHIP->value) {
                $this->rollingChipWallet = $wallet['balance'];
            }
        }
    }

    public function toText(): string
    {
        $message = '';
        $message .= __('tgbot.homepage.main_wallet_balance', ['balance' => $this->mainWallet]) . "\n";
        if ($this->isMultipleWallet) {
            $message .= __('tgbot.homepage.game_wallet_balance', ['balance' => $this->gameWallet]) . "\n";
        }
        $message .= __('tgbot.homepage.rolling_chip_wallet_balance', ['balance' => $this->rollingChipWallet]);

        return $message;
    }
}
