<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\TrustedProxies;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/TrustedProxies/SyncTrustedProxiesCommandTest.php
 */
class SyncTrustedProxiesCommandTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();
        $base = tempnam(sys_get_temp_dir(), 'trusted-proxies-sync-test-');
        unlink($base);
        $this->outputPath = $base . '.json';
    }

    private function fakeCloudflareSuccess(): void
    {
        Http::fake([
            'example.invalid/ips-v4' => Http::response("0.0.0.0/24\n4.5.6.0/24\n", 200),
            'example.invalid/ips-v6' => Http::response("2001:db8::/32\n", 200),
        ]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->outputPath)) {
            unlink($this->outputPath);
        }
        parent::tearDown();
    }

    public function test_syncs_and_writes_expected_structure(): void
    {
        $this->fakeCloudflareSuccess();

        $this->artisan('trusted-proxies:sync', [
            'path' => $this->outputPath,
            '--no-mlytics' => true,
        ])->assertSuccessful();

        $this->assertFileExists($this->outputPath);

        $decoded = json_decode((string) file_get_contents($this->outputPath), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('generated_at', $decoded);
        $this->assertArrayHasKey('source', $decoded);
        $this->assertSame(['0.0.0.0/24', '0.0.0.0/24'], $decoded['cidrs']['cloudflare_ipv4']);
        $this->assertSame(['2001:db8::/32'], $decoded['cidrs']['cloudflare_ipv6']);
        $this->assertNotEmpty($decoded['cidrs']['google_lb_ipv4']);
        $this->assertNotEmpty($decoded['cidrs']['private_reserved']);
        $this->assertSame([], $decoded['cidrs']['mlytics_ipv4']);
    }

    public function test_dry_run_does_not_write_file(): void
    {
        $this->fakeCloudflareSuccess();

        $this->artisan('trusted-proxies:sync', [
            'path' => $this->outputPath,
            '--no-mlytics' => true,
            '--dry-run' => true,
        ])->assertExitCode(2);

        $this->assertFileDoesNotExist($this->outputPath);
    }

    public function test_cloudflare_fetch_failure_aborts_without_writing(): void
    {
        Http::fake([
            'example.invalid/ips-v4' => Http::response('', 500),
        ]);

        $this->artisan('trusted-proxies:sync', [
            'path' => $this->outputPath,
            '--no-mlytics' => true,
        ])->assertFailed();

        $this->assertFileDoesNotExist($this->outputPath);
    }

    public function test_mlytics_file_option_is_used(): void
    {
        $this->fakeCloudflareSuccess();

        $mlyticsFile = tempnam(sys_get_temp_dir(), 'mlytics-test-');
        file_put_contents($mlyticsFile, "0.0.0.0/24\n# comment\n\n7.8.9.0/24\n");

        try {
            $this->artisan('trusted-proxies:sync', [
                'path' => $this->outputPath,
                '--mlytics-file' => $mlyticsFile,
            ])->assertSuccessful();

            $decoded = json_decode((string) file_get_contents($this->outputPath), true);
            $this->assertSame(['0.0.0.0/24'], $decoded['cidrs']['mlytics_ipv4']);
        } finally {
            unlink($mlyticsFile);
        }
    }

    public function test_picks_latest_mlytics_file_by_filename_date_when_no_option_given(): void
    {
        $this->fakeCloudflareSuccess();

        $dir = storage_path('app/trusted-proxies');
        $older = "{$dir}/mlytics_20260101waflist.txt";
        $newer = "{$dir}/mlytics_20260601waflist.txt";
        file_put_contents($older, "0.0.0.0/24\n");
        file_put_contents($newer, "0.0.0.0/24\n");

        try {
            $this->artisan('trusted-proxies:sync', [
                'path' => $this->outputPath,
            ])->assertSuccessful();

            $decoded = json_decode((string) file_get_contents($this->outputPath), true);
            $this->assertSame(['0.0.0.0/24'], $decoded['cidrs']['mlytics_ipv4']);
        } finally {
            unlink($older);
            unlink($newer);
        }
    }
}
