<?php

declare(strict_types=1);

namespace App\Console\Commands\Test;

use App\Services\TonGateway;
use Illuminate\Console\Command;
use Olifanton\Ton\Contracts\Wallets\Transfer;

class TonTest extends Command
{
    /**
     * php artisan test:ton
     */
    protected $signature = 'test:ton';

    protected TonGateway $gateway;

    protected bool $isMainnet = false; // 是否使用主網

    protected string $apiUrl = '';

    protected string $toncenterApiKey = '';

    protected array $mnemonic = [];

    public function handle(): void
    {
        $this->gateway = new TonGateway;

        // $this->deployWallet(1);

        // $this->transferBackToMain(1);
        // $this->transferBackToMain(85);
        // $this->transferBackToMain(86);
        // $this->transferBackToMain(87);

        // $this->transfer('0QDK_FuRnyuw09Ak790qSCVl6wH5KzR2tc1p4VNO9mM6NKqW', '0.1', 85, 'hello');

        // dump($this->getSubWalletAddress(85));

        // dump($this->getSubWalletBalance(TonSubWalletIdEnum::MAIN_WALLET->value));
        // dump($this->getSubWalletBalance(1));
        // dump($this->getSubWalletBalance(85));
        // dump($this->getSubWalletBalance(86));
        // dump($this->getSubWalletBalance(87));

        $address = $this->gateway->getSubWalletAddress(1);

        // file_put_contents(__DIR__ . '/transactions_0.txt', json_encode($this->getSubWalletTransactions(0)));
        file_put_contents(__DIR__ . '/transactions_1.txt', json_encode($this->gateway->getTransactions($address)));
        file_put_contents(__DIR__ . '/simple_transactions_1.txt', json_encode($this->gateway->getSimpleTransactions($address)));
        // file_put_contents(__DIR__ . '/transactions_85.txt', json_encode($this->getSubWalletTransactions(85)));
        // file_put_contents(__DIR__ . '/transactions_86.txt', json_encode($this->getSubWalletTransactions(86)));
        // file_put_contents(__DIR__ . '/transactions_87.txt', json_encode($this->getSubWalletTransactions(87)));

    }
}
