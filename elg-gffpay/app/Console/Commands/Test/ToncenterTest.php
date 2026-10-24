<?php

declare(strict_types=1);

namespace App\Console\Commands\Test;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Toncenter API 文件
 * https://example.invalid/api/v2/#/
 */
class ToncenterTest extends Command
{
    /**
     * php artisan test:toncenter
     */
    protected $signature = 'test:toncenter';

    // protected string $apiUrl = 'https://example.invalid/api/v2'; // 主網
    protected string $apiUrl = 'https://example.invalid/api/v2'; // 測試網

    protected string $toncenterApiKey = '';

    public function handle(): void
    {
        $this->toncenterApiKey = [REDACTED]'third-party.ton.toncenter_api_key');
        // $address = '0QDK_FuRnyuw09Ak790qSCVl6wH5KzR2tc1p4VNO9mM6NKqW';
        // $address = '0QARj_4Y6o-OfPOGbauU7s5x2a85smsZeCQKnDtmcqAZPPZ5';
        // echo json_encode($this->getAddressInformation($address));
        // echo json_encode($this->getWalletInformation($address));
        // echo json_encode($this->getTransactions($address));
        // file_put_contents(__DIR__ . '/transactions.json', json_encode($this->getTransactions($address)));
        // echo json_encode($this->getAddressState($address));
        // echo json_encode($this->getAddressBalance($address));
    }

    public function getWalletInformation(string $address)
    {
        $url = $this->apiUrl . '/getWalletInformation';
        $params = [
            'address' => $address,
        ];
        $headers = [
            'X-API-Key' => $this->toncenterApiKey,
        ];
        $response = Http::withHeaders($headers)->get($url, $params);

        $json = $response->json();
        $seqno = $json['result']['seqno'] ?? ''; // 序列號
        $balance = $json['result']['balance'] ?? ''; // 單位是nano 要除以 10^9
        $state = $json['result']['account_state'] ?? ''; // 狀態

        return $json;
    }

    public function getAddressInformation(string $address)
    {
        $url = $this->apiUrl . '/getAddressInformation';
        $params = [
            'address' => $address,
        ];
        $headers = [
            'X-API-Key' => $this->toncenterApiKey,
        ];
        $response = Http::withHeaders($headers)->get($url, $params);

        $json = $response->json();
        $state = $json['result']['state'] ?? ''; // active
        $balance = $json['result']['balance'] ?? ''; // 單位是nano 要除以 10^9

        return $json;
    }

    public function getAddressBalance(string $address)
    {
        $url = $this->apiUrl . '/getAddressBalance';
        $params = [
            'address' => $address,
        ];
        $headers = [
            'X-API-Key' => $this->toncenterApiKey,
        ];
        $response = Http::withHeaders($headers)->get($url, $params);

        $json = $response->json();

        return $json;
    }

    public function getAddressState(string $address)
    {
        $url = $this->apiUrl . '/getAddressState';
        $params = [
            'address' => $address,
        ];
        $headers = [
            'X-API-Key' => $this->toncenterApiKey,
        ];
        $response = Http::withHeaders($headers)->get($url, $params);

        $json = $response->json();

        return $json;
    }

    public function getTransactions(string $address)
    {
        $url = $this->apiUrl . '/getTransactions';
        $params = [
            'address' => $address,
        ];
        $headers = [
            'X-API-Key' => $this->toncenterApiKey,
        ];
        $response = Http::withHeaders($headers)->get($url, $params);
        $json = $response->json();

        return $json;
    }
}
