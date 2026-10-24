<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateParamsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('params', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('type')->comment('類型');
            $table->string('parameter')->comment('參數名稱');
            $table->integer('value')->comment('值');
            $table->integer('sort')->comment('排序');
            $table->string('remark')->comment('備註');
            $table->tinyInteger('status')
                ->default(1)
                ->comment('狀態 0:停用,1:啟用');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });

        DB::table('params')->insert(array(
            'type' => 'analytics_type',
            'parameter' => 'fb',
            'value' => 1,
            'sort' => 0,
            'status' => 1,
            'remark' => 'Facebook  Pixel'
        ));

        DB::table('params')->insert(array(
            'type' => 'analytics_type',
            'parameter' => 'ga',
            'value' => 2,
            'sort' => 0,
            'status' => 1,
            'remark' => 'Google Analytics'
        ));
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('params');
    }
}
