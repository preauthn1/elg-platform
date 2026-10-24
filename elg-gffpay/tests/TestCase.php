<?php

namespace Tests;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery;
use ReflectionNamedType;
use ReflectionObject;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    // protected int $memoryStart;

    protected array $headers = [
        'Accept-Language' => 'zh-TW',
    ];

    protected string $domain = 'localhost';

    protected function setUp(): void
    {
        parent::setUp();

        ini_set('memory_limit', '1024M'); // 設置記憶體限制

        if (!file_exists(base_path('.env.testing'))) {
            exit("Error: .env.testing file is missing. Please create one before running tests.\n");
        }

        // Some functions in Controller need this.
        $_SERVER['REMOTE_ADDR'] = '0.0.0.0';

        $this->app->make('config');

        // $this->memoryStart = memory_get_usage();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();

        gc_collect_cycles();

        app()->forgetInstances();
        $refl = new ReflectionObject($this);
        foreach ($refl->getProperties() as $prop) {
            if (!$prop->isStatic() && strpos($prop->getDeclaringClass()->getName(), 'PHPUnit_') !== 0) {
                $prop->setAccessible(true);
                $type = $prop->getType();

                // ✅ 如果沒有型別宣告，或型別允許 null，才設 null
                if (
                    !$type ||
                    ($type instanceof ReflectionNamedType && $type->allowsNull())
                ) {
                    $prop->setValue($this, null);
                }
            }
        }
    }
}
