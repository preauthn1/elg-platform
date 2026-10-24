<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ReportDocsAccessTest extends TestCase
{
    /**
     * 正式環境（Scramble 關閉）必須鎖死報告中心，避免金流稽核資料外洩。
     */
    public function test_reports_index_returns_404_when_scramble_disabled(): void
    {
        config(['scramble.enabled' => false]);

        $this->get('/docs/reports')->assertNotFound();
    }

    /**
     * API 文件可見的環境（Scramble 啟用，如本機 / dev2）即可瀏覽報告中心。
     */
    public function test_reports_index_accessible_when_scramble_enabled(): void
    {
        config(['scramble.enabled' => true]);

        $this->get('/docs/reports')->assertOk();
    }
}
