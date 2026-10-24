<?php

declare(strict_types=1);

namespace App\Console\Commands\Test\Payment;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestEasyPay extends Command
{
    protected $signature = 'test:EasyPay';

    protected $description = '測試遊戲配置能否正常運作';

    private array $vendorInfo = [
        'API_URL' => 'https://example.invalid/api',
        'MERCHANT_NAME' => 'EP001-4',
        'MERCHANT_PASSWORD' => 'QyPHj)ks0_8Z_HbsWvfrG8!v',
    ];

    public function handle(): void
    {
        $this->info('====================================');
        $this->info('开始测试 EasyPay API');
        $this->info('====================================');
        $this->newLine();

        // 根据文档，只测试 POST 方法
        $this->info('--- 测试: POST /withdraw/get_bank_list ---');
        $result = $this->getBankList();
        $this->displayResult($result);
    }

    private function displayResult(array $result): void
    {
        if (isset($result['error'])) {
            $this->error('❌ 请求失败');
        } else {
            $this->info('✅ 请求成功');
        }
        $this->newLine();
        $this->info('=== 完整响应 ===');
        $this->info(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function getBankList(): array
    {
        // 请求体为空
        $params = [];

        // 请求头中同时包含 merchant 和 Basic Auth
        $headers = [
            'merchant' => $this->vendorInfo['MERCHANT_NAME'],
        ];

        $url = $this->vendorInfo['API_URL'] . '/withdraw/get_bank_list';

        $this->info("请求 URL: {$url}");
        $this->info('请求头: ' . json_encode($headers, JSON_UNESCAPED_UNICODE));
        // $this->info('请求体: ' . json_encode($params, JSON_UNESCAPED_UNICODE));
        $this->newLine();

        try {
            $response = Http::logAction('EasyPay')
                ->withHeaders($headers)
                ->withBasicAuth($this->vendorInfo['MERCHANT_NAME'], $this->vendorInfo['MERCHANT_PASSWORD'])
                ->asJson()
                ->post($url, $params);

            $this->info('HTTP 状态码: ' . $response->status());

            if ($response->successful()) {
                $this->info('✓ 响应成功');

                return $response->json() ?? [];
            } else {
                $this->error('✗ 请求失败: HTTP ' . $response->status());
                $this->info('响应体: ' . $response->body());

                return [
                    'error' => true,
                    'status' => $response->status(),
                    'body' => $response->json() ?? [],
                ];
            }
        } catch (\Exception $e) {
            $this->error('✗ 发生异常: ' . $e->getMessage());

            return [
                'error' => true,
                'exception' => $e->getMessage(),
            ];
        }
    }
}
