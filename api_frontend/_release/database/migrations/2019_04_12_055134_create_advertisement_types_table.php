<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAdvertisementTypesTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('advertisement_types', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('path_id')->comment('位置');
            $table->integer('language_id')->comment('語系系統編號');
            $table->integer('country_id')->nullable()->default(0)->comment('國家系統編號');
            $table->integer('agent_id')->nullable()->default(0)->comment('代理系統編號');
            $table->integer('column')->comment('欄位數');
            $table->boolean('is_public')->comment('0:private,1:public');
            $table->dateTime('start_time')->comment('開始日期');
            $table->dateTime('end_time')->comment('結束日期');
            $table->integer('sort')->comment('排序');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('advertisement_types');
    }
}
