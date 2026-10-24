<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterAgentsNewColumnCustomizedSettingGroupId extends Migration
{

    protected $default_id = 1;

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //先動態查出DB的預設資料
        $queryDefault = DB::table("agent_groups")
            ->where("agent_group_type" , 1)
            ->where("is_default" , 1)
            ->select("id")
            ->first();
            
        if(is_object($queryDefault))
        {
            $this->default_id =  $queryDefault->id;
        }            

        Schema::table('agents', function($table) {
            $table->integer('customized_setting_group_id')  
            ->default($this->default_id )
            ->comment('個性化設置_組別ID (agent_groups 資料表)');
        });
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