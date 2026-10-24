<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableAgentPersonalSettings extends Migration
{ 
    protected $table_name = 'agent_personal_settings';      
    
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create($this->table_name, function (Blueprint $table) {            
            $table->increments('id');
            $table->integer('agent_id')->comment('代理id');
            $table->integer('setting_id')->comment('設置鍵值');
            $table->string('sub_category' , 50)->comment('子分類(可能是語系)')->nullable();
            $table->string('value' , 200)->comment('設置value');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->integer('created_by')->useCurrent()->comment('建立人');
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))->comment('更新日期');
        });

        DB::statement("ALTER TABLE `$this->table_name` comment '代理帳號個人設置'");          
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
