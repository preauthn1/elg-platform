<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class AppServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Schema::defaultStringLength(191);
        // 添加自定義驗證規則,允许字母、數字
        Validator::extend('r_alpha_num', function ($attribute, $value, $parameters, $validator) {
            return is_string($value) && preg_match('/^[A-Za-z0-9]+$/u', $value);
        });
        // 添加自定義驗證規則,允许字母、數字和_-空白
        Validator::extend('r_alpha_dash', function ($attribute, $value, $parameters, $validator) {
            return is_string($value) && preg_match('/^[A-Za-z0-9-_\s]+$/u', $value);
        });
        Validator::extend('domain', function ($attribute, $value, $parameters, $validator) {
            return is_string($value) && preg_match('/^[A-Za-z0-9.]+$/u', $value);
        });
        Validator::extend('float', function ($attribute, $value, $parameters, $validator) {
            return preg_match('/^\d{0,6}.?\d{0,5}$/u', $value);
        });
        Validator::extend('base64image', function ($attribute, $value, $parameters, $validator) {
            $value = str_replace(' ', '+', $value);
            $explode = explode(',', $value);
            $pos = strpos($value, ';');
            $type = explode(':', substr($value, 0, $pos));
            if (count($type) < 2) {
                return false;
            }
            $mime = explode('/', $type[1]);
            $allow = [
                'png',
                'jpg',
                'jpeg',
                'gif',
                'svg'
            ];
            // check file format
            if (! in_array(strtolower($mime[1]), $allow)) {
                return false;
            }
            // check base64 format
            if (! preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $explode[1])) {
                return false;
            }
            return true;
        });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
