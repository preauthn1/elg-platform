<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableAgentGroups extends Migration
{
    protected $table_name = 'agent_groups';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {      

        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->commit('PK流水號');
                $table->integer('agent_group_type')
                    ->comment('1:基本個性化設置組別, 2:財務組別, 3:簡訊設置組別, 4.聊天組別, 5.首頁遊戲組別');
                $table->integer('organization_id')->default(1)->comment('所屬組織');
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

        DB::table($this->table_name)
            ->insert(
                array(
                    array(
                        'id' => 1,
                        'agent_group_type' => 1,
                        'organization_id' => 1,
                        'name' => '預設個性化分組',
                        'memo' => '代理個性化設置',
                        'is_default' => 1,
                    ),
                    array(
                        'id' => 2,
                        'agent_group_type' => 2,
                        'organization_id' => 1,
                        'name' => '預設金流分組',
                        'memo' => '代理金流設置',
                        'is_default' => 1,
                    ),
                    array(
                        'id' => 3,
                        'agent_group_type' => 3,
                        'organization_id' => 1,
                        'name' => '預設簡訊分組',
                        'memo' => '代理簡訊設置',
                        'is_default' => 1,
                    ),
                    array(
                        'id' => 4,
                        'agent_group_type' => 4,
                        'organization_id' => 1,
                        'name' => '預設聊天分組',
                        'memo' => '代理聊天設置',
                        'is_default' => 1,
                    ),
                    array(
                        'id' => 5,
                        'agent_group_type' => 5,
                        'organization_id' => 1,
                        'name' => '預設遊戲分組',
                        'memo' => '代理遊戲設置',
                        'is_default' => 1,
                    ),
                    array(
                        'id' => 6,
                        'agent_group_type' => 1,
                        'organization_id' => 2,
                        'name' => '開發個性化分組',
                        'memo' => '代理個性化設置',
                        'is_default' => 0,
                    ),
                    array(
                        'id' => 7,
                        'agent_group_type' => 2,
                        'organization_id' => 2,
                        'name' => '開發金流分組',
                        'memo' => '代理金流設置',
                        'is_default' => 0,
                    ),
                    array(
                        'id' => 8,
                        'agent_group_type' => 3,
                        'organization_id' => 2,
                        'name' => '開發簡訊分組',
                        'memo' => '代理簡訊設置',
                        'is_default' => 0,
                    ),
                    array(
                        'id' => 9,
                        'agent_group_type' => 4,
                        'organization_id' => 2,
                        'name' => '開發聊天分組',
                        'memo' => '代理聊天設置',
                        'is_default' => 0,
                    ),
                    array(
                        'id' => 10,
                        'agent_group_type' => 5,
                        'organization_id' => 2,
                        'name' => '開發遊戲分組',
                        'memo' => '代理遊戲設置',
                        'is_default' => 0,
                    )
                )
            );
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