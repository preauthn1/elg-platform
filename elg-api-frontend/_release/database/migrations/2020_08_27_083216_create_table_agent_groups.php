<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableAgentGroups extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {      
        $table_name = "agent_groups";
        if (!Schema::hasTable($table_name)) {
            Schema::create($table_name, function (Blueprint $table) {
                $table->increments('id')->commit('PK流水號');
                $table->integer('agent_group_type')->commit('分組類型_( 1:基本個性化設置組別、2:財務組別  3:簡訊設置組別 )');
                $table->string('name')->commit('備註顯示名稱(需要多語系嗎)');
                $table->string('memo')->commit('備註');
                $table->tinyinteger('is_default')->default(0)->commit('是否為預設組別');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
            });
        }

        DB::table($table_name)
            ->insert(array(            
                'agent_group_type' => 1,
                'name' => '預設分組',
                'memo' => '代理個性化設置',
                'is_default' => 1,            
            ));

        DB::table($table_name)
            ->insert(array(            
                'agent_group_type' => 1,
                'name' => '越南站設置',
                'memo' => '代理個性化設置',
                'is_default' => 0,          
            ));

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('agent_groups');
    }
}