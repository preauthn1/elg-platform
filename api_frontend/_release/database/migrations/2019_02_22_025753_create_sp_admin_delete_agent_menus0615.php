<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpAdminDeleteAgentMenus0615 extends Migration
{
    protected $procedure_name = 'ADMIN_delete_agent_menus';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(
	IN p_code VARCHAR(50)
)
BEGIN
	
    # 查看menu是否存在
    IF EXISTS(SELECT * FROM agent_menus WHERE code = p_code) THEN
		DELETE FROM agent_menus WHERE code = p_code;
    END IF;
END";
        DB::unprepared('DROP procedure IF EXISTS '.$this->procedure_name.'');
        DB::unprepared($procedure);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::unprepared('DROP procedure IF EXISTS '.$this->procedure_name.'');
    }
}
