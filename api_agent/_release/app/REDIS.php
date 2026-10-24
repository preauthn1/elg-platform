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

    /* 和api_fronted共用 */
    public function setCommon($keys, $data)
    {
        $result = \Redis::set(
            implode(':', $keys),
            json_encode($data)
        );
        if ($result == 'OK') {
            return true;
        } else {
            return false;
        }
    }

    public function setWithExpiredTime($keys, $data , $seconds)
    {
        $result = \Redis::set(env('REDIS_PREFIX') . ':' . implode(':', $keys), json_encode($data) , 'EX' ,$seconds);
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

    /* 刪除關鍵字 */
    public function deleteByKeyword($text)
    {
        $keys = \Redis::keys('*:'.$text.'*');
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

    /* 完全清空當前redis */
    public function flushDb()
    {
        if (\Redis::flushDb()) {
            return true;
        } else {
            return false;
        }
    }

    /* 和api_fronted共用 */
    public function decrCommon($keys)
    {
        return \Redis::decr(implode(':', $keys));
    }
}