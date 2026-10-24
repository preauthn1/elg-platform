<?php

declare(strict_types=1);

namespace App\Services\BloomFilter;

use Illuminate\Support\Facades\Redis;

class BloomFilterService
{
    protected $hashFunctions = ['md5', 'sha1', 'crc32'];

    public function add($key, $value)
    {
        $redis = Redis::connection('db_15');
        foreach ($this->hashFunctions as $func) {
            $hash = hexdec(substr((string) $func($value), 0, 8));
            $offset = $hash % 10000000; // 假設位元陣列長度為一千萬
            $redis->setbit($key, $offset, 1);
        }
    }

    public function exists($key, $value)
    {
        $redis = Redis::connection('db_15');
        foreach ($this->hashFunctions as $func) {
            $hash = hexdec(substr((string) $func($value), 0, 8));
            $offset = $hash % 10000000;
            if ($redis->getbit($key, $offset) == 0) {
                return false; // 只要有一個位元是 0，絕對不存在
            }
        }

        return true; // 可能存在（存在誤報風險）
    }
}
