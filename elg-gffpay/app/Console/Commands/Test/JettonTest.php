<?php

declare(strict_types=1);

namespace App\Console\Commands\Test;

use App\Services\JettonGateway;
use Illuminate\Console\Command;
use Olifanton\Ton\AddressState;

class JettonTest extends Command
{
    /**
     * php artisan test:jetton
     */
    protected $signature = 'test:jetton {action? : deploy|state|mint|balance|calc|transfer}';

    protected $description = 'Jetton 測試命令: deploy(部署), state(查詢狀態), mint(鑄造), balance(查餘額), calc(計算地址), transfer(轉帳)';

    protected JettonGateway $gateway;

    protected string $jettonContentUrl = 'https://example.invalid/036c97bf516d3996c9b0';

    // 已部署的 Jetton Minter 地址 (測試用，請替換成你自己部署的)
    protected string $jettonMinterAddress = '';

    public function handle(): void
    {
        $this->gateway = new JettonGateway;

        $this->info('Network: ' . ($this->gateway->isMainnet() ? 'Mainnet' : 'Testnet'));

        // 若是正式環境則報錯
        if ($this->gateway->isMainnet()) {
            $this->error('This command is not allowed in mainnet environment');

            return;
        }

        $action = $this->argument('action');

        if (!$action) {
            $this->showMenu();

            return;
        }

        match ($action) {
            'deploy' => $this->deployJettonMinter(),
            'state' => $this->checkDeployState(),
            'mint' => $this->mintJettons(),
            'balance' => $this->checkBalance(),
            'calc' => $this->calculateMinterAddress(),
            'transfer' => $this->transferJetton(),
            default => $this->error("Unknown action: {$action}"),
        };
    }

    private function showMenu(): void
    {
        $this->info('=== Jetton 測試命令 ===');
        $this->line('');
        $this->line('可用操作:');
        $this->line('  php artisan test:jetton deploy   - 部署新的 Jetton Minter');
        $this->line('  php artisan test:jetton state    - 查詢 Jetton Minter 部署狀態');
        $this->line('  php artisan test:jetton mint     - 鑄造 Jettons');
        $this->line('  php artisan test:jetton balance  - 查詢 Jetton 餘額');
        $this->line('  php artisan test:jetton calc     - 根據 metadata URL 計算 Minter 地址');
        $this->line('  php artisan test:jetton transfer - 轉帳 Jettons (支援子錢包)');
        $this->line('');
    }

    /**
     * 部署新的 Jetton Minter
     */
    private function deployJettonMinter(): void
    {
        $this->info('=== 部署 Jetton Minter ===');

        $walletAddress = $this->gateway->getSubWalletAddress(0);
        $this->info("Deploy wallet address: {$walletAddress}");

        // 檢查錢包狀態
        $state = $this->gateway->getAddressState($walletAddress);
        if ($state !== AddressState::ACTIVE) {
            $this->error('Deploy wallet is not active. Please fund it first.');

            return;
        }

        // Jetton 元數據 URL
        $jettonContentUrl = $this->jettonContentUrl;

        $initialMintAmount = $this->ask('Enter initial mint amount (0 to skip)', '1000000');

        if (!$this->confirm('Do you want to deploy?')) {
            $this->info('Cancelled.');

            return;
        }

        $this->info('Deploying...');

        try {
            $result = $this->gateway->deployJettonMinter($jettonContentUrl, $initialMintAmount);

            $this->info('✅ Jetton Minter deployed successfully!');
            $this->info("Minter address: {$result['minter_address']}");
            $this->info("Jetton Wallet address: {$result['jetton_wallet_address']}");
            $this->info('');
            $this->line("💡 提示: 使用相同的 metadata URL 可隨時用 'calc' 重新計算此地址");

        } catch (\Exception $e) {
            $this->error('Deploy failed: ' . $e->getMessage());
        }
    }

    /**
     * 查詢 Jetton Minter 部署狀態
     */
    private function checkDeployState(): void
    {
        $this->info('=== 查詢 Jetton Minter 狀態 ===');

        $minterAddress = $this->gateway->calculateMinterAddress($this->jettonContentUrl);

        try {
            $state = $this->gateway->getAddressState($minterAddress);

            $stateText = match ($state) {
                AddressState::ACTIVE => '✅ Active (已啟動)',
                AddressState::UNINITIALIZED => '⏳ Uninitialized (未初始化)',
                AddressState::FROZEN => '❄️ Frozen (已凍結)',
                default => '❓ Unknown',
            };

            $this->info("Minter address: {$minterAddress}");
            $this->info("State: {$stateText}");

            // 如果已啟動，獲取更多信息
            if ($state === AddressState::ACTIVE) {
                $this->info('');
                $this->info('Fetching Jetton data...');

                $jettonData = $this->gateway->getJettonData($minterAddress);

                $this->table(
                    ['Property', 'Value'],
                    [
                        ['Total Supply', $jettonData['total_supply'] . ' Jettons'],
                        ['Mintable', $jettonData['mintable'] ? 'Yes' : 'No'],
                        ['Admin Address', $jettonData['admin_address'] ?? 'N/A'],
                    ]
                );
            }

        } catch (\Exception $e) {
            $this->error('Failed to check state: ' . $e->getMessage());
        }
    }

    /**
     * 鑄造 Jettons
     */
    private function mintJettons(): void
    {
        $this->info('=== 鑄造 Jettons ===');

        $minterAddress = $this->gateway->calculateMinterAddress($this->jettonContentUrl);

        $amount = $this->ask('Enter amount to mint', '1000000');

        $walletAddress = $this->gateway->getSubWalletAddress(0);
        $this->info("Minting to wallet: {$walletAddress}");
        $this->info("Amount: {$amount} Jettons");

        if (!$this->confirm('Do you want to mint?')) {
            $this->info('Cancelled.');

            return;
        }

        try {
            $jettonWalletAddress = $this->gateway->mint($minterAddress, $amount);

            $this->info('✅ Mint transaction sent!');
            $this->info("Jetton Wallet address: {$jettonWalletAddress}");

        } catch (\Exception $e) {
            $this->error('Mint failed: ' . $e->getMessage());
        }
    }

    /**
     * 查詢 Jetton 餘額
     */
    private function checkBalance(): void
    {
        $this->info('=== 查詢 Jetton 餘額 ===');

        $minterAddress = $this->gateway->calculateMinterAddress($this->jettonContentUrl);

        $walletId = (int) $this->ask('Enter wallet ID (0 = main wallet)', '0');
        $walletAddressStr = $this->gateway->getSubWalletAddress($walletId);

        try {
            $jettonWalletAddress = $this->gateway->getJettonWalletAddress($minterAddress, $walletAddressStr);
            $this->info("Jetton Wallet address: {$jettonWalletAddress}");

            // 檢查 Jetton Wallet 狀態
            $isActive = $this->gateway->isJettonWalletActive($minterAddress, $walletAddressStr);

            if (!$isActive) {
                $this->warn('Jetton Wallet is not active (balance is 0 or wallet not initialized)');
                $this->info('Balance: 0');

                return;
            }

            $balance = $this->gateway->getJettonBalance($minterAddress, $walletAddressStr);

            $this->info('');
            $this->table(
                ['Property', 'Value'],
                [
                    ['Wallet Address', $walletAddressStr],
                    ['Jetton Wallet', $jettonWalletAddress],
                    ['Balance', "{$balance} Jettons"],
                ]
            );

        } catch (\Exception $e) {
            $this->error('Failed to get balance: ' . $e->getMessage());
        }
    }

    /**
     * 計算 Minter 地址（不部署，僅計算）
     */
    private function calculateMinterAddress(): void
    {
        $this->info('=== 計算 Jetton Minter 地址 ===');
        $this->line('');
        $this->line('合約地址由 admin 地址 + metadata URL + wallet code 決定');
        $this->line('相同參數會得到相同地址，可用於重新計算已部署的 Minter 地址');
        $this->line('');

        $jettonContentUrl = $this->ask(
            'Enter Jetton metadata URL (JSON format)',
            'https://example.invalid/036c97bf516d3996c9b0'
        );

        $minterAddress = $this->gateway->calculateMinterAddress($jettonContentUrl);
        $walletAddress = $this->gateway->getSubWalletAddress(0);

        $this->info('');
        $this->table(
            ['Property', 'Value'],
            [
                ['Admin Wallet', $walletAddress],
                ['Metadata URL', $jettonContentUrl],
                ['Minter Address', $minterAddress],
            ]
        );
    }

    /**
     * 轉帳 Jettons
     */
    private function transferJetton(): void
    {
        $this->info('=== 轉帳 Jettons ===');

        $minterAddress = $this->gateway->calculateMinterAddress($this->jettonContentUrl);

        // 來源錢包 ID
        $fromWalletId = (int) $this->ask('Enter source wallet ID (0 = main wallet)', '0');
        $fromWalletAddress = $this->gateway->getSubWalletAddress($fromWalletId);
        $this->info("From wallet (ID: {$fromWalletId}): {$fromWalletAddress}");

        // 檢查來源錢包狀態
        $fromState = $this->gateway->getAddressState($fromWalletAddress);
        if ($fromState !== AddressState::ACTIVE) {
            $this->error('Source wallet is not active. Please deploy and fund it first.');

            return;
        }

        // 顯示來源錢包餘額
        $fromBalance = $this->gateway->getJettonBalance($minterAddress, $fromWalletAddress);
        $this->info("Source Jetton balance: {$fromBalance}");

        // 目標錢包
        $toInput = $this->ask('Enter destination (wallet ID or address)', '');
        if (is_numeric($toInput)) {
            $toWalletId = (int) $toInput;
            $toWalletAddress = $this->gateway->getSubWalletAddress($toWalletId);
            $this->info("To wallet (ID: {$toWalletId}): {$toWalletAddress}");
        } else {
            $toWalletAddress = $toInput;
            $this->info("To address: {$toWalletAddress}");
        }

        // 轉帳金額
        $amount = $this->ask('Enter amount to transfer', '100');

        // 備註 (可選)
        $comment = $this->ask('Enter comment (optional)', '');

        $this->info('');
        $this->table(
            ['Property', 'Value'],
            [
                ['From', $fromWalletAddress . " (ID: {$fromWalletId})"],
                ['To', $toWalletAddress],
                ['Amount', "{$amount} Jettons"],
                ['Comment', $comment ?: '(none)'],
            ]
        );

        if (!$this->confirm('Do you want to transfer?')) {
            $this->info('Cancelled.');

            return;
        }

        try {
            $this->gateway->transferJetton(
                $minterAddress,
                $toWalletAddress,
                $amount,
                9, // decimals
                $comment,
                $fromWalletId
            );

            $this->info('✅ Transfer transaction sent!');
            $this->line('');
            $this->line('💡 提示: 如果目標錢包的 Jetton Wallet 尚未初始化，此次轉帳會自動初始化它');

        } catch (\Exception $e) {
            $this->error('Transfer failed: ' . $e->getMessage());
        }
    }
}
