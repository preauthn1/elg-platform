<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use App\Enums\Db\WalletDeploymentStatusEnum;
use App\Enums\Db\WalletStatusEnum;
use App\Models\Wallet;
use App\Services\TonGateway;
use Illuminate\Console\Command;

/**
 * 在DB wallets 建立 1000 個子錢包, 無呼叫三方, 尚未部屬
 */
class CreateWallet extends Command
{
    /**
     * php artisan tool:create_wallet
     */
    protected $signature = 'tool:create_wallet';

    public function handle(): void
    {
        $tonGateway = new TonGateway;

        $insertArray = [];
        for ($i = 1; $i <= 1000; $i++) {
            $address = $tonGateway->getSubWalletAddress($i);
            $insertArray[] = [
                'sub_wallet_id' => $i,
                'address' => $address,
                'balance' => 0,
                'status' => WalletStatusEnum::IDLE->value,
                'deployment_status' => WalletDeploymentStatusEnum::NOT_DEPLOYED->value,
                'is_enabled' => 1,
            ];
            $this->info('Ton_log, Wallet ' . $i . ' address: ' . $address);
        }
        Wallet::insert($insertArray);
        $this->info('Ton_log, Create wallet success');
    }
}
