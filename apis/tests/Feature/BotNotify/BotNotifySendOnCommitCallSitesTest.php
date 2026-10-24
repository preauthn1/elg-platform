<?php

declare(strict_types=1);

namespace Tests\Feature\BotNotify;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * 守住 BotNotify::sendOnCommit() 的使用範圍。
 *
 * sendOnCommit() 是唯一會因為交易 rollBack 就把通知整則丟掉的送法，只給「這件事沒發生就不該通知」
 * 的業務通知用（大額通知）。排查用的告警若誤用它，rollBack 時就會無聲消失、沒有任何線索
 * （曾因此讓代付回調的防重複出款告警送不出去）。新增呼叫點必須在這裡一併登記，逼一次人工判斷。
 */
class BotNotifySendOnCommitCallSitesTest extends TestCase
{
    /**
     * 允許使用 sendOnCommit() 的檔案（相對 app/）
     */
    private const ALLOWED_FILES = [
        'Services/LargeAmountNotifyService.php',
        'Services/WithdrawalService.php',
    ];

    public function test_send_on_commit_is_only_used_by_business_notifications(): void
    {
        $callSites = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            // .stub 一併掃：腳手架模板裡的送法會複製進未來的每一家 provider
            if (!in_array($file->getExtension(), ['php', 'stub'], true)) {
                continue;
            }

            // 只認呼叫（BotNotify::sendOnCommit() 或 channel(...)->sendOnCommit()），
            // 不認 BotNotifyService 裡的方法宣告與各處 docblock 提到的方法名
            if (preg_match('/(?:::|->)sendOnCommit\(/', file_get_contents($file->getPathname())) === 0) {
                continue;
            }

            $callSites[] = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
        }

        sort($callSites);

        // 掃到的與登記的必須完全一致：多出來的要判斷該不該丟掉通知，少掉的代表登記過期
        $this->assertSame(
            self::ALLOWED_FILES,
            $callSites,
            'sendOnCommit() 的呼叫點與登記不符。排查用的告警請改用 send()；'
            . '確定是「沒發生就不該通知」的業務通知，才把檔案加進 ALLOWED_FILES。'
        );
    }
}
