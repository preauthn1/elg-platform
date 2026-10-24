<?php
namespace App;

class REDIS
{

    public function set($keys, $data, $ttl = 3600)
    {
        $result = \Redis::set(
            env('REDIS_PREFIX') . ':' . implode(':', $keys),
            json_encode($data),
            'EX',
            $ttl
        );
        if ($result == 'OK') {
            return true;
        } else {
            return false;
        }
    }

    public function get($keys)
    {
        $data = \Redis::get(env('REDIS_PREFIX') . ':' . implode(':', $keys));
        return json_decode($data, JSON_UNESCAPED_UNICODE);
    }

    public function exists($keys)
    {
        if (\Redis::exists(env('REDIS_PREFIX') . ':' . implode(':', $keys))) {
            return true;
        } else {
            return false;
        }
    }

    public function delete($keys)
    {
        $num = 0;
        $keys = \Redis::keys('*:' . implode(':', $keys) . '*');
        foreach ($keys as $key) {
            \Redis::del($key);
            $num++;
        }
        return $num;
    }

    public function expire($keys, $second)
    {
        return \Redis::expire(env('REDIS_PREFIX') . ':' . implode(':', $keys), $second);
    }

    public function incr($keys)
    {
        return \Redis::incr(env('REDIS_PREFIX') . ':' . implode(':', $keys));
    }
}
