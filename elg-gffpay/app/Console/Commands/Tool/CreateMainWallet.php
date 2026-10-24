<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use App\Enums\Db\SubWalletIdEnum;
use App\Enums\Db\WalletDeploymentStatusEnum;
use App\Enums\Db\WalletStatusEnum;
use App\Models\Wallet;
use App\Services\TonGateway;
use Illuminate\Console\Command;

/**
 * 在DB wallets 建立 主錢包
 */
class CreateMainWallet extends Command
{
    /**
     * php artisan tool:create_main_wallet
     */
    protected $signature = 'tool:create_main_wallet';

    public function handle(): void
    {
        $tonGateway = new TonGateway;

        $insertArray = [];
        $address = $tonGateway->getSubWalletAddress(SubWalletIdEnum::MAIN_WALLET->value);
        $insertArray[] = [
            'sub_wallet_id' => SubWalletIdEnum::MAIN_WALLET->value,
            'address' => $address,
            'balance' => 0,
            'status' => WalletStatusEnum::IDLE->value,
            'deployment_status' => WalletDeploymentStatusEnum::NOT_DEPLOYED->value,
            'is_enabled' => 1,
        ];
        $this->info('Ton_log, Wallet ' . SubWalletIdEnum::MAIN_WALLET->value . ' address: ' . $address);

        Wallet::insert($insertArray);
        $this->info('Ton_log, Create wallet success');
    }
}
