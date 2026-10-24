<?php
namespace App;

class REDIS
{

    public function set($keys, $data)
    {
        $result = \Redis::set(env('REDIS_PREFIX') . ':' . implode(':', $keys), json_encode($data));
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
        $keys = \Redis::keys('*:' . implode(':', $keys) . '*');
        foreach ($keys as $key) {
            \Redis::del($key);
        }
    }

    public function expire($keys, $second)
    {
        if (\Redis::expire(env('REDIS_PREFIX') . ':' . implode(':', $keys), $second)) {
            return true;
        } else {
            return false;
        }
    }
}
