<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpAdminInsertAgentMenus0615 extends Migration
{
    protected $procedure_name = 'ADMIN_insert_agent_menus';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(
	IN p_code VARCHAR(50),
    IN p_name VARCHAR(50),
    IN p_parent VARCHAR(50),
    IN p_url VARCHAR(50),
    IN p_symbol VARCHAR(50),
    IN p_action VARCHAR(50),
    IN p_status TINYINT(1),
    IN p_sort INT,
    IN p_admin INT
)
BEGIN
	
    DECLARE Parent_ID INT;
    
    # 查看menu是否存在
    IF NOT EXISTS(SELECT * FROM agent_menus WHERE code = p_code) THEN
		# 取得上層menu id
        
		IF p_parent = '0' THEN
			SET Parent_ID = 0;
		ELSE
			SELECT id INTO Parent_ID
			FROM agent_menus
			WHERE code = p_parent;
		END IF;
        
		INSERT INTO agent_menus(code, name, parent, url, symbol, action, status, sort, admin)
        VALUES(p_code, p_name, Parent_ID, p_url, p_symbol, p_action, p_status, p_sort, p_admin);
    END IF;
        
	# 幫admin新增權限
    INSERT INTO agent_permissions(agent_id, agent_menu_id, action, admin)
    SELECT 1, m.id, '', 0
    FROM agent_menus as m
    WHERE m.code = p_code
    AND NOT EXISTS(SELECT * FROM agent_permissions AS p WHERE m.id = p.agent_menu_id AND p.agent_id = 1);

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
