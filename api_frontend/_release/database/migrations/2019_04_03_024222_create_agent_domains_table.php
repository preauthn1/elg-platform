<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAgentDomainsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('agent_domains', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->comment('代理系統編號');
            $table->string('domain')->unique()->comment('網址名稱');
            $table->integer('template_id')->default(0)->comment('模版系統編號,0為公版');
            $table->string('logo_pc')->nullable()->comment('電腦版Logo');
            $table->string('logo_mobile')->nullable()->comment('手機版Logo');
            $table->string('icon')->nullable()->comment('favorite icon');
            $table->string('add_home_image')->nullable()->comment('添加主螢幕教學圖片網址');
            $table->string('title')->nullable()->comment('網站名稱');
            $table->tinyInteger('type')->default(0)->comment('類型: 0:正常, 1:推廣用(因為可能會用到短網址, 故其他在回給前端可能不適合)');
            $table->string('info')->nullable()->comment('其他資訊');
            $table->tinyInteger('status')->default(1)->comment('0未使用,1使用中');
            $table->integer('sort')->default(0)->comment('排序');
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
        Schema::dropIfExists('agent_domains');
    }
}
