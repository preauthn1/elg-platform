<?php

declare(strict_types=1);

namespace App\Services;

use App\Custom\LoggingHttpClient;
use Http\Client\Common\HttpMethodsClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Illuminate\Support\Facades\Log;
use Olifanton\Interop\Address;
use Olifanton\Interop\Boc\SnakeString;
use Olifanton\Interop\Units;
use Olifanton\Mnemonic\TonMnemonic;
use Olifanton\Ton\AddressState;
use Olifanton\Ton\ContractAwaiter;
use Olifanton\Ton\Contracts\Jetton\JettonMinter;
use Olifanton\Ton\Contracts\Jetton\JettonMinterOptions;
use Olifanton\Ton\Contracts\Jetton\JettonWallet;
use Olifanton\Ton\Contracts\Jetton\JettonWalletOptions;
use Olifanton\Ton\Contracts\Jetton\MintOptions;
use Olifanton\Ton\Contracts\Jetton\TransferJettonOptions;
use Olifanton\Ton\Contracts\Wallets\Transfer;
use Olifanton\Ton\Contracts\Wallets\TransferOptions;
use Olifanton\Ton\Contracts\Wallets\V4\WalletV4Options;
use Olifanton\Ton\Contracts\Wallets\V4\WalletV4R2;
use Olifanton\Ton\Deployer;
use Olifanton\Ton\DeployOptions;
use Olifanton\Ton\JettonOffchainMetadataFetcher;
use Olifanton\Ton\SendMode;
use Olifanton\Ton\Transports\Toncenter\ClientOptions;
use Olifanton\Ton\Transports\Toncenter\ToncenterHttpV2Client;
use Olifanton\Ton\Transports\Toncenter\ToncenterTransport;
use Psr\Log\LoggerInterface;

class JettonGateway
{
    // 常數定義
    private const DEPLOY_FEE = '0.5';

    private const TRANSFER_FEE = '0.1';

    private const MINT_FEE = '0.05';

    private const FORWARD_AMOUNT = '0.0000001';

    protected ToncenterHttpV2Client $toncenter;

    protected ToncenterTransport $transport;

    protected HttpMethodsClient $httpClient;

    protected bool $isMainnet = false;

    protected string $apiUrl = '';

    protected string $toncenterApiKey = '';

    protected array $mnemonic = [];

    // 緩存 KeyPair 避免重複生成
    private ?object $cachedKeyPair = null;

    public function __construct()
    {
        $this->isMainnet = (bool) config('third-party.ton.ton_is_main_net');
        $this->apiUrl = $this->isMainnet ? 'https://example.invalid/api/v2' : 'https://example.invalid/api/v2';
        $this->toncenterApiKey = [REDACTED]'third-party.ton.toncenter_api_key');
        $this->mnemonic = explode(' ', trim(config('third-party.ton.ton_wallet_mnemonic')));

        // 建立 HTTP client, 使用 LoggingHttpClient 包裝 PSR-18 的 client
        $psr18BaseClient = Psr18ClientDiscovery::find();
        $loggingHttpClient = new LoggingHttpClient($psr18BaseClient, app(LoggerInterface::class));
        $this->httpClient = new HttpMethodsClient(
            $loggingHttpClient,
            Psr17FactoryDiscovery::findRequestFactory(),
            Psr17FactoryDiscovery::findStreamFactory(),
        );

        // 初始化 Toncenter API client
        $this->toncenter = new ToncenterHttpV2Client(
            $this->httpClient,
            new ClientOptions(
                $this->apiUrl,
                $this->toncenterApiKey,
            ),
        );

        // 建立 transport
        $this->transport = new ToncenterTransport($this->toncenter);
    }

    /**
     * 設置 Transport（主要用於測試）
     */
    public function setTransport(ToncenterTransport $transport): self
    {
        $this->transport = $transport;

        return $this;
    }

    /**
     * 設置 Toncenter Client（主要用於測試）
     */
    public function setToncenter(ToncenterHttpV2Client $toncenter): self
    {
        $this->toncenter = $toncenter;

        return $this;
    }

    /**
     * 設置是否為主網（主要用於測試）
     */
    public function setIsMainnet(bool $isMainnet): self
    {
        $this->isMainnet = $isMainnet;
        $this->apiUrl = $this->isMainnet ? 'https://example.invalid/api/v2' : 'https://example.invalid/api/v2';

        return $this;
    }

    /**
     * 設置助記詞（主要用於測試）
     */
    public function setMnemonic(array $mnemonic): self
    {
        $this->mnemonic = $mnemonic;
        $this->cachedKeyPair = null;

        return $this;
    }

    /**
     * 計算 Jetton Minter 地址（不部署，僅計算）
     *
     * 合約地址由 admin 地址 + metadata URL + wallet code 決定
     * 相同參數會得到相同地址，可用於重新計算已部署的 Minter 地址
     *
     * @param  string  $jettonContentUrl  Jetton 元數據 URL (JSON 格式)
     * @return string Minter 合約地址
     */
    public function calculateMinterAddress(string $jettonContentUrl): string
    {
        $deployWallet = $this->createWallet();

        $minter = new JettonMinter(
            new JettonMinterOptions(
                adminAddress: $deployWallet->getAddress(),
                jettonContentUrl: $jettonContentUrl,
                jettonWalletCode: JettonWallet::getDefaultCode(),
            ),
        );

        return $this->formatAddress($minter->getAddress());
    }

    /**
     * 部署新的 Jetton Minter 合約
     *
     * @param  string  $jettonContentUrl  Jetton 元數據 URL (JSON 格式)
     * @param  string  $initialMintAmount  初始鑄造數量
     * @param  int  $decimals  小數位數 (預設 9)
     * @return array{minter_address: string, jetton_wallet_address: string}
     */
    public function deployJettonMinter(
        string $jettonContentUrl,
        string $initialMintAmount = '1000000',
        int $decimals = 9
    ): array {
        $kp = $this->getKeyPair();
        $logger = app(LoggerInterface::class);

        // 創建部署錢包
        $deployWallet = $this->createWallet();

        Log::info('Jetton_log, Deploying Jetton Minter from wallet: ' . $this->formatAddress($deployWallet->getAddress()));

        // 創建 Minter 實例
        $minter = new JettonMinter(
            new JettonMinterOptions(
                adminAddress: $deployWallet->getAddress(),
                jettonContentUrl: $jettonContentUrl,
                jettonWalletCode: JettonWallet::getDefaultCode(),
            ),
        );

        // 部署合約
        $deployer = new Deployer($this->transport);
        $deployer->setLogger($logger);

        $deployOptions = new DeployOptions(
            $deployWallet,
            $kp->secretKey,
            Units::toNano(self::DEPLOY_FEE),
        );

        $deployer->deploy($deployOptions, $minter);

        // 等待合約啟動
        $awaiter = new ContractAwaiter($this->transport);
        $awaiter->setLogger($logger);
        $awaiter->waitForActive($minter->getAddress());

        Log::info('Jetton_log, Minter deployed at: ' . $this->formatAddress($minter->getAddress()));

        // 如果需要初始鑄造
        if (bccomp($initialMintAmount, '0', 9) > 0) {
            $this->mintJettons($minter, $deployWallet, $initialMintAmount, $decimals);
        }

        // 獲取 Jetton Wallet 地址
        $jettonWalletAddress = $minter->getJettonWalletAddress($this->transport, $deployWallet->getAddress());

        return [
            'minter_address' => $this->formatAddress($minter->getAddress()),
            'jetton_wallet_address' => $this->formatAddress($jettonWalletAddress),
        ];
    }

    /**
     * 鑄造 Jettons
     */
    private function mintJettons(
        JettonMinter $minter,
        WalletV4R2 $wallet,
        string $amount,
        int $decimals = 9
    ): void {
        $kp = $this->getKeyPair();

        $transfer = $wallet->createTransferMessage(
            [
                new Transfer(
                    dest: $minter->getAddress(),
                    amount: Units::toNano(self::MINT_FEE),
                    payload: JettonMinter::createMintBody(new MintOptions(
                        jettonAmount: Units::toNano($amount, $decimals),
                        destination: $wallet->getAddress(),
                        amount: Units::toNano(self::MINT_FEE),
                    )),
                    bounce: false,
                ),
            ],
            new TransferOptions(
                seqno: (int) $wallet->seqno($this->transport),
            )
        );

        $this->transport->sendMessage($transfer, $kp->secretKey);

        Log::info(sprintf('Jetton_log, Minted %s jettons to %s', $amount, $this->formatAddress($wallet->getAddress())));
    }

    /**
     * 查詢 Jetton 餘額
     *
     * @param  string  $jettonMinterAddress  Jetton Minter 合約地址
     * @param  string  $walletAddress  要查詢的錢包地址
     * @param  int  $decimals  小數位數 (預設 9, USDT 為 6)
     * @return string 餘額
     */
    public function getJettonBalance(
        string $jettonMinterAddress,
        string $walletAddress,
        int $decimals = 9
    ): string {
        $minter = JettonMinter::fromAddress(
            $this->transport,
            new Address($jettonMinterAddress),
        );

        $jettonWalletAddress = $minter->getJettonWalletAddress(
            $this->transport,
            new Address($walletAddress)
        );

        $jettonWallet = new JettonWallet(
            new JettonWalletOptions(
                address: $jettonWalletAddress,
            ),
        );

        try {
            $data = $jettonWallet->getWalletData($this->transport);

            return (string) Units::fromNano($data->balance, $decimals);
        } catch (\Exception $e) {
            Log::warning('Jetton_log, Failed to get jetton balance: ' . $e->getMessage());

            return '0';
        }
    }

    /**
     * 查詢 USDT 餘額 (便捷方法)
     *
     * @param  string  $walletAddress  要查詢的錢包地址
     * @return string USDT 餘額
     */
    public function getUsdtBalance(string $walletAddress): string
    {
        $usdtMinterAddress = $this->getUsdtMinterAddress();

        return $this->getJettonBalance($usdtMinterAddress, $walletAddress, Units::USDt);
    }

    /**
     * 轉帳 Jetton
     *
     * @param  string  $jettonMinterAddress  Jetton Minter 合約地址
     * @param  string  $recipientAddress  接收者錢包地址 (非 Jetton Wallet 地址)
     * @param  string  $amount  轉帳數量
     * @param  int  $decimals  小數位數 (預設 9, USDT 為 6)
     * @param  string  $comment  備註 (可選)
     * @param  int  $fromSubWalletId  來源子錢包ID
     */
    public function transferJetton(
        string $jettonMinterAddress,
        string $recipientAddress,
        string $amount,
        int $decimals = 9,
        string $comment = '',
        int $fromSubWalletId = 0
    ): void {
        $kp = $this->getKeyPair();
        $wallet = $this->createWallet($fromSubWalletId);
        $walletAddress = $wallet->getAddress();
        $recipient = new Address($recipientAddress);

        Log::info(sprintf(
            'Jetton_log, Transferring %s jettons from %s to %s',
            $amount,
            $this->formatAddress($walletAddress),
            $recipientAddress
        ));

        // 獲取 Jetton Minter 和 Wallet
        $minter = JettonMinter::fromAddress(
            $this->transport,
            new Address($jettonMinterAddress),
        );

        $jettonWalletAddress = $minter->getJettonWalletAddress($this->transport, $walletAddress);

        $jettonWallet = new JettonWallet(
            new JettonWalletOptions(
                address: $jettonWalletAddress,
            ),
        );

        Log::debug(sprintf(
            'Jetton_log, Jetton wallet address: %s',
            $this->formatAddress($jettonWalletAddress),
        ));

        // 檢查 Jetton Wallet 狀態
        $state = $this->transport->getState($jettonWalletAddress);
        if ($state !== AddressState::ACTIVE) {
            throw new \RuntimeException('Jetton wallet is not initialized, cannot perform transfer');
        }

        // 準備轉帳 payload
        $transferOptions = new TransferJettonOptions(
            jettonAmount: Units::toNano($amount, $decimals),
            toAddress: $recipient,
            responseAddress: $walletAddress,
            forwardAmount: Units::toNano(self::FORWARD_AMOUNT),
        );

        // 如果有備註，添加 forward payload
        if (!empty($comment)) {
            $transferOptions = new TransferJettonOptions(
                jettonAmount: Units::toNano($amount, $decimals),
                toAddress: $recipient,
                responseAddress: $walletAddress,
                forwardPayload: SnakeString::fromString($comment)->cell(true),
                forwardAmount: Units::toNano(self::FORWARD_AMOUNT),
            );
        }

        // 創建並發送交易
        $extMessage = $wallet->createTransferMessage(
            [
                new Transfer(
                    dest: $jettonWalletAddress,
                    amount: Units::toNano(self::TRANSFER_FEE),
                    payload: $jettonWallet->createTransferBody($transferOptions),
                    sendMode: SendMode::IGNORE_ERRORS->combine(
                        SendMode::PAY_GAS_SEPARATELY,
                        SendMode::CARRY_ALL_REMAINING_INCOMING_VALUE
                    ),
                ),
            ],
            new TransferOptions(
                seqno: (int) $wallet->seqno($this->transport),
            )
        );

        try {
            $this->transport->sendMessage($extMessage, $kp->secretKey);
            Log::info('Jetton_log, Jetton transfer sent successfully');
        } catch (\Exception $e) {
            Log::error('Jetton_failed, ' . $e->getMessage(), ['exception' => $e]);
            throw $e;
        }
    }

    /**
     * 轉帳 USDT (便捷方法)
     *
     * @param  string  $recipientAddress  接收者錢包地址
     * @param  string  $amount  USDT 數量
     * @param  string  $comment  備註 (可選)
     * @param  int  $fromSubWalletId  來源子錢包ID
     */
    public function transferUsdt(
        string $recipientAddress,
        string $amount,
        string $comment = '',
        int $fromSubWalletId = 0
    ): void {
        $usdtMinterAddress = $this->getUsdtMinterAddress();

        $this->transferJetton(
            $usdtMinterAddress,
            $recipientAddress,
            $amount,
            Units::USDt,
            $comment,
            $fromSubWalletId
        );
    }

    /**
     * 獲取 Jetton 元數據
     *
     * @param  string  $jettonMinterAddress  Jetton Minter 合約地址
     * @return array{jetton_data: array, metadata: array|null}
     */
    public function getJettonMetadata(string $jettonMinterAddress): array
    {
        $metadataFetcher = new JettonOffchainMetadataFetcher($this->transport, $this->httpClient);

        $minter = JettonMinter::fromAddress(
            $this->transport,
            new Address($jettonMinterAddress)
        );

        $jettonData = $minter->getJettonData($this->transport);

        try {
            $metadata = $metadataFetcher->getMetadataFromData($jettonData);
        } catch (\Exception $e) {
            Log::warning('Jetton_log, Failed to fetch offchain metadata: ' . $e->getMessage());
            $metadata = null;
        }

        return [
            'jetton_data' => $jettonData->asPrintableArray(),
            'metadata' => $metadata,
        ];
    }

    /**
     * 獲取 Jetton Wallet 地址
     *
     * @param  string  $jettonMinterAddress  Jetton Minter 合約地址
     * @param  string  $walletAddress  錢包地址
     * @return string Jetton Wallet 地址
     */
    public function getJettonWalletAddress(string $jettonMinterAddress, string $walletAddress): string
    {
        $minter = JettonMinter::fromAddress(
            $this->transport,
            new Address($jettonMinterAddress),
        );

        $jettonWalletAddress = $minter->getJettonWalletAddress(
            $this->transport,
            new Address($walletAddress)
        );

        return $this->formatAddress($jettonWalletAddress);
    }

    /**
     * 檢查 Jetton Wallet 是否已初始化
     *
     * @param  string  $jettonMinterAddress  Jetton Minter 合約地址
     * @param  string  $walletAddress  錢包地址
     */
    public function isJettonWalletActive(string $jettonMinterAddress, string $walletAddress): bool
    {
        $minter = JettonMinter::fromAddress(
            $this->transport,
            new Address($jettonMinterAddress),
        );

        $jettonWalletAddress = $minter->getJettonWalletAddress(
            $this->transport,
            new Address($walletAddress)
        );

        $state = $this->transport->getState($jettonWalletAddress);

        return $state === AddressState::ACTIVE;
    }

    /**
     * 獲取地址狀態
     */
    public function getAddressState(string $address): AddressState
    {
        return $this->transport->getState(new Address($address));
    }

    /**
     * 獲取 Jetton 數據
     *
     * @return array{total_supply: string, mintable: bool, admin_address: string|null}
     */
    public function getJettonData(string $jettonMinterAddress): array
    {
        $minter = JettonMinter::fromAddress(
            $this->transport,
            new Address($jettonMinterAddress)
        );

        $jettonData = $minter->getJettonData($this->transport);
        $printable = $jettonData->asPrintableArray();

        return [
            'total_supply' => (string) Units::fromNano($jettonData->totalSupply),
            'mintable' => $printable['isMutable'] ?? false,
            'admin_address' => $jettonData->adminAddress?->toString(true, true, false, !$this->isMainnet),
        ];
    }

    /**
     * 鑄造 Jettons (公開方法)
     *
     * @param  string  $jettonMinterAddress  Jetton Minter 合約地址
     * @param  string  $amount  鑄造數量
     * @param  int  $decimals  小數位數
     * @return string Jetton Wallet 地址
     */
    public function mint(string $jettonMinterAddress, string $amount, int $decimals = 9): string
    {
        $kp = $this->getKeyPair();
        $wallet = $this->createWallet();

        $minter = JettonMinter::fromAddress(
            $this->transport,
            new Address($jettonMinterAddress)
        );

        $transfer = $wallet->createTransferMessage(
            [
                new Transfer(
                    dest: $minter->getAddress(),
                    amount: Units::toNano(self::MINT_FEE),
                    payload: JettonMinter::createMintBody(new MintOptions(
                        jettonAmount: Units::toNano($amount, $decimals),
                        destination: $wallet->getAddress(),
                        amount: Units::toNano(self::MINT_FEE),
                    )),
                    bounce: false,
                ),
            ],
            new TransferOptions(
                seqno: (int) $wallet->seqno($this->transport),
            )
        );

        $this->transport->sendMessage($transfer, $kp->secretKey);

        Log::info(sprintf('Jetton_log, Minted %s jettons to %s', $amount, $this->formatAddress($wallet->getAddress())));

        return $this->formatAddress($minter->getJettonWalletAddress($this->transport, $wallet->getAddress()));
    }

    /**
     * 獲取是否為主網
     */
    public function isMainnet(): bool
    {
        return $this->isMainnet;
    }

    /**
     * 獲取子錢包的 Jetton 餘額
     */
    public function getSubWalletJettonBalance(string $jettonMinterAddress, int $subWalletId, int $decimals = 9): string
    {
        $walletAddress = $this->getSubWalletAddress($subWalletId);

        return $this->getJettonBalance($jettonMinterAddress, $walletAddress, $decimals);
    }

    /**
     * 獲取子錢包的 USDT 餘額
     */
    public function getSubWalletUsdtBalance(int $subWalletId): string
    {
        $walletAddress = $this->getSubWalletAddress($subWalletId);

        return $this->getUsdtBalance($walletAddress);
    }

    /**
     * 從子錢包轉帳 Jetton 到主錢包
     */
    public function transferJettonBackToMain(
        string $jettonMinterAddress,
        int $subWalletId,
        string $amount,
        int $decimals = 9
    ): void {
        $mainWalletAddress = $this->getSubWalletAddress(0);

        $this->transferJetton(
            $jettonMinterAddress,
            $mainWalletAddress,
            $amount,
            $decimals,
            '',
            $subWalletId
        );
    }

    /**
     * 從子錢包轉帳 USDT 到主錢包
     */
    public function transferUsdtBackToMain(int $subWalletId, string $amount): void
    {
        $mainWalletAddress = $this->getSubWalletAddress(0);

        $this->transferUsdt($mainWalletAddress, $amount, '', $subWalletId);
    }

    /**
     * 獲取子錢包地址
     */
    public function getSubWalletAddress(int $subWalletId = 0): string
    {
        $wallet = $this->createWallet($subWalletId);

        return $this->formatAddress($wallet->getAddress());
    }

    /**
     * 獲取 USDT Minter 地址
     */
    private function getUsdtMinterAddress(): string
    {
        // 主網 USDT 地址: https://example.invalid/EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs
        // 測試網需要自行部署或使用其他測試代幣
        if ($this->isMainnet) {
            return 'EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs';
        }

        // 測試網 USDT 地址 (可根據實際情況配置)
        return config('third-party.ton.testnet_usdt_minter_address', '');
    }

    /**
     * 創建錢包實例
     */
    private function createWallet(int $subWalletId = 0): WalletV4R2
    {
        $kp = $this->getKeyPair();

        if ($subWalletId > 0) {
            return new WalletV4R2(
                new WalletV4Options(
                    publicKey: $kp->publicKey,
                    walletId: $subWalletId,
                ),
            );
        }

        return new WalletV4R2(
            new WalletV4Options(
                publicKey: $kp->publicKey,
            ),
        );
    }

    /**
     * 獲取 KeyPair（使用緩存）
     */
    private function getKeyPair(): object
    {
        if ($this->cachedKeyPair === null) {
            $this->cachedKeyPair = TonMnemonic::mnemonicToKeyPair($this->mnemonic);
        }

        return $this->cachedKeyPair;
    }

    /**
     * 格式化地址為字符串
     */
    private function formatAddress(Address $address): string
    {
        return $address->toString(true, true, false, !$this->isMainnet);
    }
}
