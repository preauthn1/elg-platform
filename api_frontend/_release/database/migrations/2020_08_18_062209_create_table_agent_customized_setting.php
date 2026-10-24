<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableAgentCustomizedSetting extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $table_name = "agent_customized_setting";
    
        if(!Schema::hasTable($table_name))
        {
            Schema::create($table_name, function (Blueprint $table) 
            {
                $table->increments('id')->comment('PK流水號');
                $table->integer('template_id')->comment('模板ID');
                $table->integer('setting_key')->comment('主要分類鍵值 (請參考 AgentCustomizedSettingKeyEnum)');
                $table->string('sub_category' ,50)->comment('子分類 (同一組key、可能有多種設置 e.g. 語系)');
                $table->string('value', 50)->comment('設值數值');
                $table->string('memo')->comment('備註');
                $table->timestamp('created_at')->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');     
            });     

            DB::statement("ALTER TABLE `$table_name` comment '代理個性化設置'");
        }

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}