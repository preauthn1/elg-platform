<?php

declare(strict_types=1);

namespace App\Services\Report;

use Illuminate\Support\Facades\File;

/**
 * payment-callback-analysis.json 的內容雜湊快取。
 *
 * - providerHash：單一金流商目錄所有 .php 內容的 sha256（任一檔變動即失效）。
 * - globalHash：共用回調 trait + PROMPT_VERSION 的 sha256（共用語意 / workflow prompt 改動 → 全體失效）。
 *
 * 用途：report:payment-providers:stale 判斷哪些金流商需要重新 LLM 分析；
 * report:payment-providers:merge 套用新分析並蓋章 hash。詳見 .claude/workflows/payment-callback-analysis.js。
 */
class PaymentCallbackAnalysisCache
{
    /** workflow 的 prompt / schema 有意義變動時手動 +1，使全體 globalHash 失效重跑。 */
    public const PROMPT_VERSION = 'v3';

    private const PROVIDERS_DIR = 'Services/Payment/Providers';

    private const SHARED_TRAITS = [
        'Services/Payment/Traits/DepositCallbackTrait.php',
        'Services/Payment/Traits/WithdrawalCallbackTrait.php',
    ];

    /**
     * 單一金流商目錄所有 .php 內容雜湊。
     */
    public function providerHash(string $name): string
    {
        $dir = app_path(self::PROVIDERS_DIR . "/{$name}");
        if (!File::isDirectory($dir)) {
            return '';
        }

        $files = collect(File::allFiles($dir))
            ->filter(fn ($f) => strtolower($f->getExtension()) === 'php')
            ->sortBy(fn ($f) => $f->getRelativePathname())
            ->values();

        $buf = '';
        foreach ($files as $f) {
            $buf .= $f->getRelativePathname() . "\0" . File::get($f->getRealPath()) . "\0";
        }

        return hash('sha256', $buf);
    }

    /**
     * 共用語意（回調 trait + prompt 版本）雜湊。
     */
    public function globalHash(): string
    {
        $parts = [self::PROMPT_VERSION];
        foreach (self::SHARED_TRAITS as $rel) {
            $path = app_path($rel);
            $parts[] = File::exists($path) ? File::get($path) : '';
        }

        return hash('sha256', implode("\0", $parts));
    }

    /**
     * 從 config/payment.php 取 provider 目錄名清單。
     *
     * @return array<int, string>
     */
    public function configProviderNames(): array
    {
        $names = [];
        foreach ((array) config('payment.provider', []) as $class) {
            if (preg_match('#Providers\\\\([^\\\\]+)\\\\Driver#', (string) $class, $m)) {
                $names[] = $m[1];
            }
        }
        sort($names);

        return $names;
    }

    /**
     * 比對現況與 JSON 內存的 hash，回報需重跑與已移除的金流商。
     *
     * @param  array<int, string>  $configNames
     * @param  array<string, mixed>  $json
     * @return array{stale: array<int, string>, removed: array<int, string>, globalChanged: bool}
     */
    public function diff(array $configNames, array $json): array
    {
        $stored = $json['providers'] ?? [];
        $globalChanged = ($json['globalHash'] ?? null) !== $this->globalHash();

        $stale = [];
        foreach ($configNames as $name) {
            $hashChanged = ($stored[$name]['sourceHash'] ?? null) !== $this->providerHash($name);
            if ($globalChanged || !isset($stored[$name]) || $hashChanged) {
                $stale[] = $name;
            }
        }

        $removed = array_values(array_diff(array_keys($stored), $configNames));

        return ['stale' => $stale, 'removed' => $removed, 'globalChanged' => $globalChanged];
    }

    /**
     * 套用新分析、蓋章 hash、移除已下架者、寫入 globalHash。
     *
     * @param  array<string, mixed>  $json  既有 JSON
     * @param  array<string, mixed>  $fresh  新分析 entries（provider => fields，不含 hash）
     * @param  array<int, string>  $configNames
     * @param  bool  $reseed  為 true 時重新蓋章「所有」既有 entry 的 hash（初始化用）
     * @return array<string, mixed>
     */
    public function merge(array $json, array $fresh, array $configNames, bool $reseed = false): array
    {
        $providers = $json['providers'] ?? [];

        foreach ($fresh as $name => $entry) {
            unset($entry['sourceHash']);
            $entry['sourceHash'] = $this->providerHash($name);
            $providers[$name] = $entry;
        }

        if ($reseed) {
            foreach (array_keys($providers) as $name) {
                $providers[$name]['sourceHash'] = $this->providerHash($name);
            }
        }

        // 移除已不在 config 的金流商
        $providers = array_intersect_key($providers, array_flip($configNames));
        ksort($providers);

        $json['providers'] = $providers;
        $json['globalHash'] = $this->globalHash();

        return $json;
    }
}
