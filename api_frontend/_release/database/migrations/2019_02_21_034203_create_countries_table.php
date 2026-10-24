<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCountriesTable extends Migration
{
    protected $table_name = 'countries';
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
                $table->string('code', 10)->comment('國家代碼');
                $table->string('name', 32)->comment('國家名稱');
                $table->integer('country_code')->default(0)->comment('國碼');
                $table->string('phone_rule')->default(0)->comment('電話號碼規則');
                $table->tinyInteger('status')->default(0)->comment('狀態 0:停用,1:啟用');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->comment('更新者ID');
            });

            DB::table($this->table_name)
                ->insert(
                    array(
                        'id' => 1,
                        'code' => 'MY',
                        'name' => 'Malaysia',
                        'country_code' => '60',
                        'phone_rule' => '^[1-9]\d{8,9}$',
                        'status' => 1,
                        'admin' => 0
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
