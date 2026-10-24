<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use App\Enums\Db\WalletDeploymentStatusEnum;
use App\Models\Wallet;
use App\Services\TonService;
use Illuminate\Console\Command;

/**
 * 部屬錢包
 */
class DeployWallet extends Command
{
    /**
     * php artisan tool:deploy_wallet
     */
    protected $signature = 'tool:deploy_wallet';

    public function __construct(
        protected TonService $tonService,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $wallets = Wallet::query()
            ->where('deployment_status', WalletDeploymentStatusEnum::NOT_DEPLOYED->value)
            ->where('sub_wallet_id', '<=', 100)
            ->get();

        foreach ($wallets as $wallet) {
            $this->tonService->deployWallet($wallet);
            $this->info('Ton_log, Deploy wallet ' . $wallet->sub_wallet_id . '');

            // 要等上鏈後才能部屬下一個錢包, 避免部屬失敗
            sleep(30);
        }
    }
}
