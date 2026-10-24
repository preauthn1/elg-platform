<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMemberInputsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('member_inputs', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('agent_id')->comment('代理系統編號');
            $table->integer('type_id')->comment('輸入框類型系統編號');
            $table->string('title')->comment('輸入框標題');
            $table->string('value')->comment('輸入框值(json格式,ex:{"male":1,"female":2})');
            $table->boolean('is_required')->comment('是否必填0:選填，1:必填');
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
        Schema::dropIfExists('member_inputs');
    }
}
