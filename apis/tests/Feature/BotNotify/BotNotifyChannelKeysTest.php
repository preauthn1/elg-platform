<?php

declare(strict_types=1);

namespace Tests\Feature\BotNotify;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * 守住 BotNotify::channel() 呼叫端與 config/bot-notify.php 頻道鍵的一致性。
 *
 * 頻道名打錯不會有任何報錯：BotNotifyService::telegram() 解析不到 chat id 時
 * 直接 return，告警靜默不發（曾因 'warning' 與 'warn' 之差，讓代收／代付
 * 金額不符通知消失）。此測試掃描 app/ 內所有字面頻道名，擋下同型錯誤。
 */
class BotNotifyChannelKeysTest extends TestCase
{
    public function test_every_literal_channel_name_exists_in_config(): void
    {
        $validChannels = array_keys(config('bot-notify.channel'));
        $violations = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            // .stub 一併掃：腳手架模板裡的頻道名會複製進未來的每一家 provider
            if (!in_array($file->getExtension(), ['php', 'stub'], true)) {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            if (preg_match_all('/BotNotify::channel\([\'"]([^\'"]+)[\'"]\)/', $content, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $channel) {
                if (!in_array($channel, $validChannels, true)) {
                    $violations[] = sprintf('%s 使用未定義的頻道「%s」', $file->getPathname(), $channel);
                }
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }
}
