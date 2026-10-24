<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateWalletTypeTable extends Migration
{

    protected $table_name = 'wallet_types';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id');
                $table->string('code', 50)->comment('錢包代碼');
                $table->string('name', 50)->comment('錢包名稱');
                $table->text('info')->nullable()->comment('錢包資訊');
                $table->smallInteger('status')->default(0)->comment('狀態: 0:停用, 1:啟用');
                $table->string('memo')->nullable()->comment('方便技術記錄用');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->commit('更新者ID');

                //unique
                $table->unique('code');
            });

            DB::statement("ALTER TABLE `$this->table_name` comment '錢包種類\\n1:平台幣(BC)\\n2~1000 法定貨幣(USD, MYR, CNY)\\n1001~2000 體驗金, 泥碼, 免費幣, BC\\n2001~3000 穩定加密貨幣(USDT, USDC, UST, USDD)\\n3001~ 加密貨幣(BTC, ETH, DOGE, SHIB, ....)'");

            DB::table($this->table_name)->insert(array(
                'id' => 1,
                'code' => 'CHIP',
                'name' => 'CHIP',
                'info' => '{"deposit":1,"withdraw":1,"freegame":0}',
                'memo' => '籌碼/平台幣',
                'status' => 1,
                'admin' => 1
            ));
            DB::table($this->table_name)->insert(array(
                'id' => 36,
                'code' => 'AUD',
                'name' => 'AUD',
                'info' => '{"deposit":1,"withdraw":1,"freegame":0}',
                'memo' => '澳大利亞元',
                'status' => 0,
                'admin' => 1
            ));
            DB::table($this->table_name)->insert(array(
                'id' => 96,
                'code' => 'BND',
                'name' => 'BND',
                'info' => '{"deposit":1,"withdraw":1,"freegame":0}',
                'memo' => '汶萊元',
                'status' => 0,
                'admin' => 1
            ));
            DB::table($this->table_name)->insert(array(
                'id' => 156,
                'code' => 'CNY',
                'name' => 'CNY',
                'info' => '{"deposit":1,"withdraw":1,"freegame":0}',
                'memo' => '人民幣',
                'status' => 0,
                'admin' => 1
            ));
            DB::table($this->table_name)->insert(array(
                'id' => 458,
                'code' => 'MYR',
                'name' => 'MYR',
                'info' => '{"deposit":1,"withdraw":1,"freegame":0}',
                'memo' => '馬來西亞令吉',
                'status' => 0,
                'admin' => 1
            ));
            DB::table($this->table_name)->insert(array(
                'id' => 702,
                'code' => 'SGD',
                'name' => 'SGD',
                'info' => '{"deposit":1,"withdraw":1,"freegame":0}',
                'memo' => '新加坡元',
                'status' => 0,
                'admin' => 1
            ));
            DB::table($this->table_name)->insert(array(
                'id' => 704,
                'code' => 'VND',
                'name' => 'VND',
                'info' => '{"deposit":1,"withdraw":1,"freegame":0}',
                'memo' => '越南盾',
                'status' => 0,
                'admin' => 1
            ));
            DB::table($this->table_name)->insert(array(
                'id' => 840,
                'code' => 'USD',
                'name' => 'USD',
                'info' => '{"deposit":1,"withdraw":1,"freegame":0}',
                'memo' => '美元',
                'status' => 0,
                'admin' => 1
            ));
            DB::table($this->table_name)->insert(array(
                'id' => 1001,
                'code' => 'FREE',
                'name' => 'Free Coin',
                'info' => '{"deposit":0,"withdraw":0,"freegame":1}',
                'memo' => '體驗金幣',
                'status' => 1,
                'admin' => 1
            ));
            DB::table($this->table_name)->insert(array(
                'id' => 1002,
                'code' => 'BONUS',
                'name' => 'Bonus',
                'info' => '{"deposit":0,"withdraw":0,"freegame":0}',
                'memo' => '紅利',
                'status' => 0,
                'admin' => 1
            ));
            DB::table($this->table_name)->insert(array(
                'id' => 1004,
                'code' => 'SCORE',
                'name' => 'Score',
                'info' => '{"deposit":0,"withdraw":0,"freegame":0}',
                'memo' => '積分',
                'status' => 0,
                'admin' => 1
            ));
            DB::table($this->table_name)->insert(array(
                'id' => 1005,
                'code' => 'DIAMOND',
                'name' => 'Diamond',
                'info' => '{"deposit":0,"withdraw":0,"freegame":0}',
                'memo' => '鑽石',
                'status' => 0,
                'admin' => 1
            ));
            DB::table($this->table_name)->insert(array(
                'id' => 2001,
                'code' => 'USDC',
                'name' => 'USD Coin',
                'info' => '{"deposit":1,"withdraw":1,"freegame":0}',
                'memo' => 'USD Coin',
                'status' => 0,
                'admin' => 1
            ));
            DB::table($this->table_name)->insert(array(
                'id' => 3001,
                'code' => 'BTC',
                'name' => 'BitCoin',
                'info' => '{"deposit":1,"withdraw":1,"freegame":0}',
                'memo' => 'BitCoin',
                'status' => 0,
                'admin' => 1
            ));
            DB::table($this->table_name)->insert(array(
                'id' => 3002,
                'code' => 'ETH',
                'name' => 'Ethereum',
                'info' => '{"deposit":1,"withdraw":1,"freegame":0}',
                'memo' => 'Ethereum',
                'status' => 0,
                'admin' => 1
            ));
        }

        $this->table_name = 'wallet_type_names';
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id');
                $table->integer('language_id')->comment('語言系統編號');
                //$table->integer('agent_group_id')->comment('代理群組系統編號');
                $table->integer('wallet_type_id')->comment('錢包種類系統編號');
                $table->string('name', 50)->comment('錢包名稱');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->commit('更新者ID');

                //index
                $table->index(array('language_id', 'wallet_type_id'));
            });
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
