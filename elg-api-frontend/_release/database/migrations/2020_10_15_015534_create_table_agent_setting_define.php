<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableAgentSettingDefine extends Migration
{
    protected $table_name = 'agent_setting_defines';      
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create($this->table_name, function (Blueprint $table) {            
            $table->integer('setting_id')->comment('PK，非自動流水號');
            $table->primary('setting_id');     
            $table->integer('parent')->comment('若是type2，會記錄上層分類id');       
            $table->integer('type')->comment('type: 1 鍵值分類，type 2: 鍵值 (分類相同的設置是整組一起編輯的)');
            $table->string('memo')->comment('備註說明')->nullable();
            $table->string('language_text_code')->comment('多語系對應代碼');
            $table->string('default_value' , 200)->comment('首次編輯要帶入的預設值')->nullable();
            $table->string('data_type' , 50)->comment('資料類型')->nullable();
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->integer('created_by')->useCurrent()->comment('建立人');
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))->comment('更新日期');
        });

        DB::statement("ALTER TABLE `$this->table_name` comment '代理個人設置鍵值定義'");        
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
