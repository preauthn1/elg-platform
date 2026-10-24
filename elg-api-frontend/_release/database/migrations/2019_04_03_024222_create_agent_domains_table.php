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
            $table->string('domain')
                ->unique()
                ->comment('網址名稱');
            $table->integer('template_id')
                ->default(0)
                ->comment('模版系統編號,0為公版');
            $table->tinyInteger('status')
                ->default(1)
                ->comment('0未使用,1使用中');
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
