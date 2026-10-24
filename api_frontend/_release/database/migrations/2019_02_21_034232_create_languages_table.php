<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateLanguagesTable extends Migration
{
    protected $table_name = 'languages';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->comment('系統編號');
                //$table->integer('country_id')->comment('國家系統編號(Country)');
                $table->string('name', 32)->comment('語言名稱');
                $table->string('code', 10)->comment('語言代碼');
                $table->string('ag', 10)->comment('AG LANG代碼');
                $table->string('xe88', 10)->nullable()->comment('xe88遊戲語系');
                $table->string('lucky', 10)->nullable()->comment('Lucky語系');
                $table->string('ebet', 10)->nullable()->comment('ebet語系');
                $table->string('isb', 10)->nullable()->comment('isb遊戲語系');
                $table->string('pt', 10)->nullable()->comment('pt遊戲語系');
                $table->string('sbo', 10)->nullable()->comment('sbo遊戲語系');
                $table->string('ygg', 10)->nullable()->comment('ygg遊戲語系');
                $table->string('wm', 10)->nullable()->comment('wm遊戲語系');
                $table->string('pg', 10)->nullable()->comment('pg遊戲語系');
                $table->string('ia', 10)->nullable()->comment('ia遊戲語系');
                $table->string('aes', 10)->nullable()->comment('aes遊戲語系');
                $table->string('target', 10)->nullable()->comment('pgs遊戲語系');
                $table->string('mt', 10)->nullable()->comment('mt遊戲語系');
                $table->string('igs', 10)->nullable()->comment('igs遊戲語系');
                $table->string('ct', 10)->nullable()->comment('ct遊戲語系');
                $table->string('dg', 10)->nullable()->comment('dg遊戲語系');
                $table->string('rm', 10)->nullable()->comment('rm遊戲語系');
                $table->string('allbet', 10)->nullable()->comment('allbet遊戲語系');
                $table->string('terra', 10)->nullable()->comment('terra遊戲語系');
                $table->string('sa', 10)->nullable()->comment('sa遊戲語系');
                $table->string('sky1388', 10)->default('eng')->comment('sky1388 language code');
                $table->string('fishing', 10)->nullable()->comment('fishing遊戲語系');
                $table->string('npt', 10)->nullable()->comment('npt遊戲語系');
                $table->string('v8', 10)->nullable()->comment('v8遊戲語系');
                $table->string('saba', 10)->nullable()->comment('saba遊戲語系');
                $table->string('obet33', 10)->nullable()->comment('obet33遊戲語系');
                $table->string('bola2', 10)->nullable()->comment('bola2遊戲語系');
                $table->string('plus100', 10)->nullable()->comment('100Plus遊戲語系');
                $table->string('spadegaming', 10)->nullable()->comment('SpadeGaming遊戲語系');
                $table->string('ns', 10)->nullable()->comment('NextSpin遊戲語系');
                $table->string('bng', 10)->nullable()->comment('Booongo遊戲語系');
                $table->string('vsport', 10)->nullable()->comment('V-sport遊戲語系');
                $table->tinyInteger('status')->default(0)->comment('狀態 0:停用,1:啟用');
                $table->timestamp('created_at')->useCurrent()->comment('建立日期');
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))->comment('更新日期');
                $table->integer('admin')->comment('更新者ID');
            });

            DB::table($this->table_name)
                ->insert(
                    array(
                        array(
                            'id' => 1,
                            'name' => 'English',
                            'code' => 'en',
                            'ag' => '3',
                            'xe88' => 'En',
                            'lucky' => 'en',
                            'ebet' => 'en_us',
                            'isb' => 'en',
                            'pt' => 'en',
                            'sbo' => 'en-US',
                            'ygg' => 'en',
                            'wm' => 'EN',
                            'pg' => 'en_GB',
                            'ia' => '2',
                            'aes' => 'en',
                            'target' => 'EN-US',
                            'mt' => 'EN-US',
                            'igs' => '2',
                            'ct' => 'en-US',
                            'dg' => 'en',
                            'rm' => 'en',
                            'allbet' => 'en',
                            'terra' => 'en-US',
                            'sa' => 'en_US',
                            'sky1388' => 'eng',
                            'fishing' => 'en-us',
                            'npt' => 'EN',
                            'v8' => 'en_us',
                            'saba' => 'en',
                            'obet33' => 'en',
                            'bola2' => 'E',
                            'spadegaming' => 'en_US',
                            'ns' => 'en_US',
                            'bng' => 'en',
                            'vsport' => 'EN-US',
                            'pp' => 'en-us',
                            'status' => 1,
                            'admin' => 1
                        ),
                        array(
                            'id' => 2,
                            'name' => '繁體中文',
                            'code' => 'zh-tw',
                            'ag' => '2',
                            'xe88' => 'CN',
                            'lucky' => 'en',
                            'ebet' => 'zh_tw',
                            'isb' => 'chs',
                            'pt' => 'ch',
                            'sbo' => 'zh-CN',
                            'ygg' => 'zh_hant',
                            'wm' => 'ZH',
                            'pg' => 'zh_TW',
                            'ia' => '3',
                            'aes' => 'cn',
                            'target' => 'ZH-CN',
                            'mt' => 'ZH-TW',
                            'igs' => '1',
                            'ct' => 'zh-TW',
                            'dg' => 'tw',
                            'rm' => 'zh',
                            'allbet' => 'zh_TW',
                            'terra' => 'zh-TW',
                            'sa' => 'en_TW',
                            'sky1388' => 'chs',
                            'fishing' => 'zh-cn',
                            'npt' => 'CH',
                            'v8' => 'en_us',
                            'saba' => 'ch',
                            'obet33' => 'zh-cn',
                            'bola2' => 'C',
                            'spadegaming' => 'en_CN',
                            'ns' => 'en_CN',
                            'bng' => 'zh-hant',
                            'vsport' => 'ZH-CH',
                            'pp' => 'zh-tw',
                            'status' => 1,
                            'admin' => 1
                        ),
                        array(
                            'id' => 3,
                            'name' => '简体中文',
                            'code' => 'zh-cn',
                            'ag' => 1,
                            'xe88' => 'CN',
                            'lucky' => 'en',
                            'ebet' => 'zh_cn',
                            'isb' => 'chs',
                            'pt' => 'zh-cn',
                            'sbo' => 'zh-CN',
                            'ygg' => 'zh_hans',
                            'wm' => 'ZH-HANT',
                            'pg' => 'zh_CN',
                            'ia' => '1',
                            'aes' => 'cn',
                            'target' => 'ZH-CN',
                            'mt' => 'ZH-CN',
                            'igs' => '1',
                            'ct' => 'zh-CN',
                            'dg' => 'cn',
                            'rm' => 'zh',
                            'allbet' => 'zh_CN',
                            'terra' => 'zh-CN',
                            'sa' => 'en_CN',
                            'sky1388' => 'chs',
                            'fishing' => 'zh-cn',
                            'npt' => 'ZH-CH',
                            'v8' => 'en_us',
                            'saba' => 'cs',
                            'obet33' => 'zh-cn',
                            'bola2' => 'C',
                            'spadegaming' => 'en_CN',
                            'ns' => 'en_CN',
                            'bng' => 'zh',
                            'vsport' => 'ZH-CH',
                            'pp' => 'zh-cn',
                            'status' => 1,
                            'admin' => 1
                        ),
                        array(
                            'id' => 4,
                            'name' => 'Melayu',
                            'code' => 'ms-my',
                            'ag' => 3,
                            'xe88' => 'En',
                            'lucky' => 'en',
                            'ebet' => 'ms_my',
                            'isb' => 'en',
                            'pt' => 'en',
                            'sbo' => 'MY',
                            'ygg' => 'en',
                            'wm' => 'EN',
                            'pg' => 'ms_MY',
                            'ia' => '2',
                            'aes' => 'en',
                            'target' => 'EN-US',
                            'mt' => 'EN-US',
                            'igs' => '3',
                            'ct' => 'en-US',
                            'dg' => 'en',
                            'rm' => 'en',
                            'allbet' => 'ms',
                            'terra' => 'en-US',
                            'sa' => 'ms',
                            'sky1388' => 'eng',
                            'fishing' => 'en-us',
                            'npt' => 'MS',
                            'v8' => 'en_us',
                            'saba' => 'en',
                            'obet33' => 'en',
                            'bola2' => 'M',
                            'spadegaming' => 'en_US',
                            'ns' => 'en_US',
                            'bng' => 'en',
                            'vsport' => 'MY-MM',
                            'pp' => 'en-us',
                            'status' => 1,
                            'admin' => 1
                        ),
                        array(
                            'id' => 5,
                            'name' => 'Indonesia',
                            'code' => 'id-id',
                            'ag' => 11,
                            'xe88' => 'En',
                            'lucky' => 'en',
                            'ebet' => 'in_id',
                            'isb' => 'id',
                            'pt' => 'en',
                            'sbo' => 'ID',
                            'ygg' => 'id',
                            'wm' => 'ID',
                            'pg' => 'id_ID',
                            'ia' => '6',
                            'aes' => 'en',
                            'target' => 'EN-US',
                            'mt' => 'EN-US',
                            'igs' => '2',
                            'ct' => 'en-US',
                            'dg' => 'en',
                            'rm' => 'en',
                            'allbet' => 'id',
                            'terra' => 'en-US',
                            'sa' => 'id',
                            'sky1388' => 'ind',
                            'fishing' => 'in-id',
                            'npt' => 'EN',
                            'v8' => 'en_us',
                            'saba' => 'id',
                            'obet33' => 'en',
                            'bola2' => 'E',
                            'spadegaming' => 'id_ID',
                            'ns' => 'id_ID',
                            'bng' => 'en',
                            'vsport' => 'ID-ID',
                            'pp' => 'en-us',
                            'status' => 1,
                            'admin' => 1
                        ),
                        array(
                            'id' => 6,
                            'name' => 'Vietnamese',
                            'code' => 'vi-vn',
                            'ag' => 8,
                            'xe88' => 'VN',
                            'lucky' => 'en',
                            'ebet' => 'vi_vn',
                            'isb' => 'vi',
                            'pt' => 'en',
                            'sbo' => 'VI',
                            'ygg' => 'vi',
                            'wm' => 'VI',
                            'pg' => 'vi_VN',
                            'ia' => '5',
                            'aes' => 'vn',
                            'target' => 'EN-US',
                            'mt' => 'EN-US',
                            'igs' => '2',
                            'ct' => 'en-US',
                            'dg' => 'vi',
                            'rm' => 'en',
                            'allbet' => 'vi',
                            'terra' => 'en-US',
                            'sa' => 'vn',
                            'sky1388' => 'vnm',
                            'fishing' => 'vi-vn',
                            'npt' => 'EN',
                            'v8' => 'vi_vn',
                            'saba' => 'vn',
                            'obet33' => 'vi-vn',
                            'bola2' => 'E',
                            'spadegaming' => 'vi_VN',
                            'ns' => 'vi_VN',
                            'bng' => 'vi',
                            'vsport' => 'VI-VN',
                            'pp' => 'en-us',
                            'status' => 1,
                            'admin' => 1
                        ),
                        array(
                            'id' => 7,
                            'name' => 'Thai',
                            'code' => 'th-th',
                            'ag' => 3,
                            'xe88' => 'THAI',
                            'lucky' => 'en',
                            'ebet' => 'th_th',
                            'isb' => 'th',
                            'pt' => 'en',
                            'sbo' => 'TH',
                            'ygg' => 'th',
                            'wm' => 'TH',
                            'pg' => 'th_TH',
                            'ia' => '4',
                            'aes' => 'th',
                            'target' => 'EN-US',
                            'mt' => 'EN-US',
                            'igs' => '2',
                            'ct' => 'th',
                            'dg' => 'th',
                            'rm' => 'en',
                            'allbet' => 'th',
                            'terra' => 'en-US',
                            'sa' => 'th',
                            'sky1388' => 'thi',
                            'fishing' => 'th-th',
                            'npt' => 'TH',
                            'v8' => 'en_us',
                            'saba' => 'th',
                            'obet33' => 'th-th',
                            'bola2' => 'E',
                            'spadegaming' => 'th_TH',
                            'ns' => 'th_TH',
                            'bng' => 'th',
                            'vsport' => 'TH-TH',
                            'pp' => 'th-th',
                            'status' => 1,
                            'admin' => 1
                        ),
                        array(
                            'id' => 8,
                            'name' => 'Taml',
                            'code' => 'ta',
                            'ag' => 3,
                            'xe88' => 'En',
                            'lucky' => 'en',
                            'ebet' => 'en_us',
                            'isb' => 'en',
                            'pt' => 'en',
                            'sbo' => 'en-US',
                            'ygg' => 'en',
                            'wm' => 'EN',
                            'pg' => 'en_GB',
                            'ia' => '2',
                            'aes' => 'en',
                            'target' => 'EN-US',
                            'mt' => 'EN-US',
                            'igs' => '2',
                            'ct' => 'en-US',
                            'dg' => 'en',
                            'rm' => 'en',
                            'allbet' => 'en',
                            'terra' => 'en-US',
                            'sa' => 'en_US',
                            'sky1388' => 'eng',
                            'fishing' => 'en-us',
                            'npt' => 'EN',
                            'v8' => 'en_us',
                            'saba' => 'en',
                            'obet33' => 'en',
                            'bola2' => 'E',
                            'spadegaming' => 'en_US',
                            'ns' => 'en_US',
                            'bng' => 'en',
                            'vsport' => 'EN-US',
                            'pp' => 'en-us',
                            'status' => 1,
                            'admin' => 1
                        )
                    )
                );
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists($this->table_name);
    }
}
