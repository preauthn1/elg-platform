<?php

declare(strict_types=1);

namespace App\Services;

use App\Custom\LoggingHttpClient;
use Http\Client\Common\HttpMethodsClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Olifanton\Interop\Address;
use Olifanton\Interop\Boc\Cell;
use Olifanton\Interop\Units;
use Olifanton\Mnemonic\TonMnemonic;
use Olifanton\Ton\AddressState;
use Olifanton\Ton\Contracts\Wallets\Transfer;
use Olifanton\Ton\Contracts\Wallets\TransferOptions;
use Olifanton\Ton\Contracts\Wallets\V4\WalletV4Options;
use Olifanton\Ton\Contracts\Wallets\V4\WalletV4R2;
use Olifanton\Ton\Deployer;
use Olifanton\Ton\DeployOptions;
use Olifanton\Ton\SendMode;
use Olifanton\Ton\Transports\Toncenter\ClientOptions;
use Olifanton\Ton\Transports\Toncenter\ToncenterHttpV2Client;
use Olifanton\Ton\Transports\Toncenter\ToncenterTransport;
use Psr\Log\LoggerInterface;

class TonGateway
{
    // 常數定義
    private const NANO_TO_TON_DIVISOR = '1000000000';

    private const MIN_TRANSFER_BALANCE = '0.01';

    private const DEPLOY_FEE = '0.05';

    private const DECIMAL_PRECISION = 9;

    protected ToncenterHttpV2Client $toncenter;

    protected ToncenterTransport $transport;

    protected bool $isMainnet = false; // 是否使用主網

    protected string $apiUrl = '';

    protected string $toncenterApiKey = '';

    protected array $mnemonic = [];

    // 緩存 KeyPair 避免重複生成
    private ?object $cachedKeyPair = null;

    /**
     * 交易類型常數
     */
    public const TX_TYPE_TON = 'ton';                      // 純 TON 轉帳

    public const TX_TYPE_JETTON_TRANSFER = 'jetton_transfer';           // Jetton Transfer (0xf8a7ea5)

    public const TX_TYPE_JETTON_INTERNAL_TRANSFER = 'jetton_internal_transfer'; // Jetton Internal Transfer (0x7362d09c)

    public const TX_TYPE_JETTON_TRANSFER_NOTIFICATION = 'jetton_transfer_notification'; // Jetton Transfer Notification (0x178d4519)

    public const TX_TYPE_UNKNOWN = 'unknown';               // 其他合約調用

    /**
     * Op Code 常數
     */
    private const OP_JETTON_TRANSFER = 0x0F8A7EA5;              // Jetton Transfer

    private const OP_JETTON_INTERNAL_TRANSFER = 0x178D4519;     // Jetton Internal Transfer

    private const OP_JETTON_TRANSFER_NOTIFICATION = 0x7362D09C; // Jetton Transfer Notification

    public function __construct()
    {
        $this->isMainnet = (bool) config('third-party.ton.ton_is_main_net');
        $this->apiUrl = $this->isMainnet ? 'https://example.invalid/api/v2' : 'https://example.invalid/api/v2';
        $this->toncenterApiKey = [REDACTED]'third-party.ton.toncenter_api_key');
        $this->mnemonic = explode(' ', trim(config('third-party.ton.ton_wallet_mnemonic')));

        // 建立 HTTP client, 使用 LoggingHttpClient 包裝 PSR-18 的 client
        $psr18BaseClient = Psr18ClientDiscovery::find();
        $loggingHttpClient = new LoggingHttpClient($psr18BaseClient, app(LoggerInterface::class));
        $httpClient = new HttpMethodsClient(
            $loggingHttpClient,
            Psr17FactoryDiscovery::findRequestFactory(),
            Psr17FactoryDiscovery::findStreamFactory(),
        );

        // 初始化 Toncenter API client
        $this->toncenter = new ToncenterHttpV2Client(
            $httpClient,
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
     * 設置 API URL（主要用於測試）
     */
    public function setApiUrl(string $apiUrl): self
    {
        $this->apiUrl = $apiUrl;

        return $this;
    }

    /**
     * 設置 API Key（主要用於測試）
     */
    public function setToncenterApiKey(string $toncenterApiKey): self
    {
        $this->toncenterApiKey = [REDACTED];

        return $this;
    }

    /**
     * 設置助記詞（主要用於測試）
     */
    public function setMnemonic(array $mnemonic): self
    {
        $this->mnemonic = $mnemonic;
        // 清除緩存的 KeyPair，因為 mnemonic 改變了
        $this->cachedKeyPair = null;

        return $this;
    }

    /**
     * 轉回子錢包餘額到主錢包
     */
    public function transferBackToMain(int $subWalletId): string
    {
        // 轉出子錢包餘額到主錢包
        $address = $this->getSubWalletAddress($subWalletId);
        $balance = $this->getAddressBalance($address);
        if (bccomp($balance, self::MIN_TRANSFER_BALANCE, self::DECIMAL_PRECISION) < 0) {
            Log::info(sprintf('子錢包 %d 餘額 %s 不足，無法轉到主錢包', $subWalletId, $balance));

            return '0';
        }
        // 留下最小餘額作為手續費
        $amount = bcsub($balance, self::MIN_TRANSFER_BALANCE, self::DECIMAL_PRECISION);
        $mainAddress = $this->getSubWalletAddress();
        $this->transfer($mainAddress, $amount, $subWalletId);

        return $amount;
    }

    /**
     * 取得地址部屬狀態
     */
    public function getState(string $address): AddressState
    {
        $address = new Address($address);
        $state = $this->transport->getState($address);

        return $state;
    }

    public function getSubWalletTransactions(int $subWalletId)
    {
        $address = $this->getSubWalletAddress($subWalletId);
        $transactions = $this->getTransactions($address);

        return $transactions;
    }

    /**
     * 取得地址交易紀錄
     */
    public function getTransactions(string $address, int $limit = 10, $lt = null, $hash = null): array
    {
        $url = $this->apiUrl . '/getTransactions';
        $params = array_filter([
            'address' => $address,
            'limit' => $limit,
            'archival' => 'true',
            'lt' => $lt,
            'hash' => $hash,
        ], fn ($value) => $value !== null);

        $response = $this->makeHttpRequest('getTransactions', $url, $params);
        $json = $response->json();

        if (!$this->validateApiResponse($json, 'getTransactions', ['address' => $address])) {
            return [];
        }

        if (!is_array($json['result'])) {
            Log::error('Ton_failed, TonGateway getTransactions result is not array', ['address' => $address]);

            return [];
        }

        return $json['result'];
    }

    /**
     * 取得簡化的交易紀錄
     *
     * @param  string  $address  錢包地址
     * @param  int  $limit  筆數限制
     * @param  string|null  $lt  起始 lt (用於分頁)
     * @param  string|null  $hash  起始 hash (用於分頁)
     * @return array 簡化的交易記錄陣列
     */
    public function getSimpleTransactions(string $address, int $limit = 10, $lt = null, $hash = null): array
    {
        $transactions = $this->getTransactions($address, $limit, $lt, $hash);
        $simpleTransactions = [];

        foreach ($transactions as $transaction) {
            $simpleTransactions[] = $this->simplifyTransaction($transaction);
        }

        return $simpleTransactions;
    }

    /**
     * 將單筆交易簡化為基本資訊
     *
     * @param  array  $transaction  原始交易記錄
     * @return array 簡化的交易資訊
     */
    public function simplifyTransaction(array $transaction): array
    {
        $inMsg = $transaction['in_msg'] ?? [];
        $outMsgs = $transaction['out_msgs'] ?? [];

        // 判斷交易方向：有 source 且非空表示入帳，否則為出帳
        $isIncoming = !empty($inMsg['source']);

        // 計算金額 (nano -> TON)
        $amount = '0';
        $source = '';
        $destination = '';

        if ($isIncoming) {
            // 入帳：從 in_msg 取得
            $amount = $inMsg['value'] ?? '0';
            $source = $inMsg['source'] ?? '';
            $destination = $inMsg['destination'] ?? '';
        } else {
            // 出帳：從 out_msgs 取得第一筆
            if (!empty($outMsgs[0])) {
                $amount = $outMsgs[0]['value'] ?? '0';
                $source = $outMsgs[0]['source'] ?? '';
                $destination = $outMsgs[0]['destination'] ?? '';
            }
        }

        // 計算總手續費 (nano -> TON)
        $fee = $transaction['fee'] ?? '0';

        // 取得交易類型
        $txType = $this->detectTransactionType($transaction);

        // 取得備註/訊息
        $message = $inMsg['message'] ?? '';

        return [
            'lt' => $transaction['transaction_id']['lt'] ?? '',
            'hash' => $transaction['transaction_id']['hash'] ?? '',
            'utime' => $transaction['utime'] ?? 0,
            'datetime' => isset($transaction['utime'])
                ? date('Y-m-d H:i:s', $transaction['utime'])
                : '',
            'source' => $source,
            'destination' => $destination,
            'amount' => $this->fromNano($amount),
            'amount_nano' => $amount,
            'fee' => $this->fromNano($fee),
            'fee_nano' => $fee,
            'tx_type' => $txType,
            'is_incoming' => $isIncoming,
            'message' => $message,
        ];
    }

    /**
     * 取得子錢包餘額
     */
    public function getSubWalletBalance(int $subWalletId): string
    {
        $address = $this->getSubWalletAddress($subWalletId);
        $balance = $this->getAddressBalance($address);

        return $balance;
    }

    /**
     * 取得地址餘額
     */
    public function getAddressBalance(string $address): string
    {
        $url = $this->apiUrl . '/getAddressBalance';
        $params = ['address' => $address];

        $response = $this->makeHttpRequest('getAddressBalance', $url, $params);
        $json = $response->json();

        if (!isset($json['result'])) {
            throw new \RuntimeException('TonGateway getAddressBalance is not have result');
        }

        $balance = $json['result'];

        if (!is_numeric($balance)) {
            throw new \RuntimeException('TonGateway getAddressBalance is not numeric, balance: ' . $balance);
        }

        $balance = (string) Units::fromNano($balance);

        return $balance;
    }

    /**
     * 取得收款子錢包地址
     *
     * @param  int  $subWalletId  子錢包ID 0為主錢包
     */
    public function getSubWalletAddress(int $subWalletId = 0): string
    {
        $wallet = $this->createWallet($subWalletId);

        return $this->formatAddress($wallet->getAddress());
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

    public function deployWallet(int $subWalletId, bool $deploySelf = false): void
    {
        $kp = $this->getKeyPair();
        $deployer = new Deployer($this->transport);
        $deployer->setLogger(app(LoggerInterface::class));

        $deployWallet = $deploySelf
            ? $this->createWallet($subWalletId)
            : $this->createWallet(0);

        Log::info('Ton_log, Deployer address: ' . $this->formatAddress($deployWallet->getAddress()));

        $newWallet = $this->createWallet($subWalletId);

        $deployOptions = new DeployOptions(
            $deployWallet,
            $kp->secretKey,
            Units::toNano(self::DEPLOY_FEE),
        );

        $fee = $deployer->estimateFee($deployOptions, $newWallet);
        Log::info('Ton_log, Deploy fee: ' . $fee);

        $deployer->deploy($deployOptions, $newWallet);

        $verifierUrl = $this->getVerifierUrl($newWallet->getAddress());
        Log::info(sprintf('Ton_log, Verifier url: %s', $verifierUrl));
    }

    /**
     * 獲取主錢包的序列號
     *
     * @deprecated 此方法目前未被使用，如需使用請先確認需求
     */
    private function getSeqno(): int
    {
        $wallet = $this->createWallet(0);

        return (int) $wallet->seqno($this->transport);
    }

    /**
     * 轉帳到指定地址
     */
    public function transfer(string $recipientAddress, string $amount, int $fromSubWalletId = 0, string $payload = ''): void
    {
        $kp = $this->getKeyPair();
        $wallet = $this->createWallet($fromSubWalletId);
        $recipient = new Address($recipientAddress);

        Log::info(
            sprintf(
                'Ton_log, Sending from: %s, to %s, amount: %s',
                $this->formatAddress($wallet->getAddress()),
                $recipientAddress,
                $amount
            )
        );

        // 建立交易訊息
        $extMsg = $wallet->createTransferMessage(
            [
                new Transfer(
                    dest: $recipient,
                    amount: Units::toNano($amount),
                    sendMode: SendMode::PAY_GAS_SEPARATELY,
                    payload: $payload,
                ),
            ],
            new TransferOptions(
                seqno: (int) $wallet->seqno($this->transport),
            )
        );

        // 發送交易
        try {
            $this->transport->sendMessage($extMsg, $kp->secretKey);
            Log::info('Ton_log, Transaction sent!');
        } catch (\Exception $e) {
            Log::error('Ton_failed, ' . $e->getMessage(), ['exception' => $e]);
            throw $e;
        }
    }

    /**
     * 轉換為不可反彈地址（bounceable address）
     */
    public function toUnbounceableAddress(string $address): string
    {
        $addressObj = new Address($address);

        return $this->formatAddress($addressObj);
    }

    /**
     * 計算轉帳gas費用
     *
     * @deprecated 此方法目前未被使用，如需使用請先確認需求
     */
    private function getTransferFee(string $recipientAddress, string $amount)
    {
        $wallet = $this->createWallet(0);
        $recipient = new Address($recipientAddress);

        // 建立交易訊息
        $extMsg = $wallet->createTransferMessage(
            [
                new Transfer(
                    dest: $recipient,
                    amount: Units::toNano($amount),
                ),
            ],
            new TransferOptions(
                seqno: (int) $wallet->seqno($this->transport),
            )
        );

        // 估算費用
        try {
            return $this->transport->estimateFee($recipient, $extMsg->cell());
        } catch (\Exception $e) {
            Log::error('Ton_Failed, ' . $e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    /**
     * 將nano ton轉換成ton
     */
    public function fromNano(string $amount): string
    {
        return bcdiv($amount, self::NANO_TO_TON_DIVISOR, self::DECIMAL_PRECISION);
    }

    /**
     * 執行 HTTP 請求
     */
    private function makeHttpRequest(string $action, string $url, array $params): \Illuminate\Http\Client\Response
    {
        $headers = ['X-API-Key' => $this->toncenterApiKey];

        return Http::logAction("Ton_Gateway_{$action}", $params)
            ->withHeaders($headers)
            ->get($url, $params);
    }

    /**
     * 驗證 API 響應
     */
    private function validateApiResponse(?array $json, string $action, array $context = []): bool
    {
        if (!$json) {
            Log::error("Ton_failed, TonGateway {$action} is empty", $context);

            return false;
        }

        if (!isset($json['ok']) || $json['ok'] !== true) {
            Log::error("Ton_failed, TonGateway {$action} is not ok", $context);

            return false;
        }

        if (!isset($json['result'])) {
            Log::error("Ton_failed, TonGateway {$action} is not have result", $context);

            return false;
        }

        return true;
    }

    /**
     * 獲取驗證器 URL
     */
    private function getVerifierUrl(Address $address): string
    {
        $url = 'https://example.invalid/' . $this->formatAddress($address);

        return $this->isMainnet ? $url : $url . '?testnet=1';
    }

    /**
     * 驗證錢包地址是否有效
     */
    public function isValidateAddress(string $address): bool
    {
        return Address::isValid($address);
    }

    /**
     * 判斷交易幣別類型
     *
     * 根據交易訊息體 (Message Body) 中的操作碼 (op_code) 判斷交易類型
     * 金額大於0且不是jetton交易則為ton交易
     *
     * @param  array  $transaction  交易記錄 (來自 getTransactions API)
     * @return string 交易類型常數 (TX_TYPE_*)
     */
    public function detectTransactionType(array $transaction): string
    {
        // 檢查是否有入帳訊息
        if (!isset($transaction['in_msg'])) {
            return self::TX_TYPE_UNKNOWN;
        }

        $inMsg = $transaction['in_msg'];

        // 檢查金額
        if (!isset($inMsg['value']) || $inMsg['value'] === '0') {
            return self::TX_TYPE_UNKNOWN;
        }

        // 檢查訊息資料
        if (!isset($inMsg['msg_data']) || !is_array($inMsg['msg_data'])) {
            return self::TX_TYPE_UNKNOWN;
        }

        $msgData = $inMsg['msg_data'];

        // 檢查訊息體 body
        if (!isset($msgData['body']) || empty($msgData['body'])) {
            return self::TX_TYPE_TON;
        }

        $opCode = $this->parseOpCodeFromBody($msgData['body']);

        if ($opCode === null) {
            return self::TX_TYPE_TON;
        }

        return match ($opCode) {
            self::OP_JETTON_TRANSFER => self::TX_TYPE_JETTON_TRANSFER,
            self::OP_JETTON_INTERNAL_TRANSFER => self::TX_TYPE_JETTON_INTERNAL_TRANSFER,
            self::OP_JETTON_TRANSFER_NOTIFICATION => self::TX_TYPE_JETTON_TRANSFER_NOTIFICATION,
            default => self::TX_TYPE_TON,
        };
    }

    /**
     * 從訊息體解析 Op Code
     *
     * @param  string  $body  Base64 編碼的 Cell 資料
     * @return int|null Op Code 或 null (如果無法解析或無 op code)
     */
    public function parseOpCodeFromBody(string $body): ?int
    {
        try {
            // 從 Base64 編碼的 BOC 解析 Cell
            $cell = Cell::oneFromBoc($body, true);
            $slice = $cell->beginParse();

            // 檢查剩餘 bits 是否足夠 32 bits (op code 長度)
            if ($slice->getUsedBits() < 32) {
                return null;
            }

            // 讀取前 32 bits 作為 op_code
            $opCode = $slice->loadUint(32)->toInt();

            return $opCode;
        } catch (\Throwable $e) {
            Log::warning('TonGateway parseOpCodeFromBody failed', [
                'error' => $e->getMessage(),
                'body' => $body,
            ]);

            return null;
        }
    }

    /**
     * 判斷交易是否為 Jetton 交易
     *
     * @param  array  $transaction  交易記錄
     */
    public function isJettonTransaction(array $transaction): bool
    {
        $type = $this->detectTransactionType($transaction);

        return in_array($type, [
            self::TX_TYPE_JETTON_TRANSFER,
            self::TX_TYPE_JETTON_INTERNAL_TRANSFER,
            self::TX_TYPE_JETTON_TRANSFER_NOTIFICATION,
        ], true);
    }

    /**
     * 判斷交易是否為純 TON 轉帳
     *
     * @param  array  $transaction  交易記錄
     */
    public function isTonTransaction(array $transaction): bool
    {
        return $this->detectTransactionType($transaction) === self::TX_TYPE_TON;
    }
}
