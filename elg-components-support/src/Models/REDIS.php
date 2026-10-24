<?php

namespace target\support\Models;

use Illuminate\Support\Facades\Redis as FacadesRedis;

class REDIS
{

    public function set($keys, $data)
    {
        $result = FacadesRedis::set(env('REDIS_PREFIX') . ':' . implode(':', $keys), json_encode($data));
        if ($result == 'OK') {
            return true;
        } else {
            return false;
        }
    }

    public function get($keys)
    {
        $data = FacadesRedis::get(env('REDIS_PREFIX') . ':' . implode(':', $keys));
        return json_decode($data, JSON_UNESCAPED_UNICODE);
    }

    public function exists($keys)
    {
        if (FacadesRedis::exists(env('REDIS_PREFIX') . ':' . implode(':', $keys))) {
            return true;
        } else {
            return false;
        }
    }

    public function delete($keys)
    {
        $keys = FacadesRedis::keys('*:' . implode(':', $keys) . '*');
        foreach ($keys as $key) {
            FacadesRedis::del($key);
        }
    }

    public function expire($key, $second)
    {
        return FacadesRedis::expire($key, $second);
    }
}
