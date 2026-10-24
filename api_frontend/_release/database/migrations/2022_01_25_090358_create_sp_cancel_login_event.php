<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpCancelLoginEvent extends Migration
{
    protected $procedure_name = 'SP_cancel_login_event';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(IN p_member_id INT)
BEGIN
	# 洗分時, 取消登入獎勵 
    # 2022/01/25 Mars 		ADD 
    
	DECLARE PrizeId, Done, Continues INT DEFAULT 0;
    DECLARE HierarchyPath VARCHAR(100) 
		DEFAULT (
			SELECT a.hierarchy_path 
			FROM members AS m 
            JOIN agents AS a ON a.id = m.parent 
			WHERE m.id = p_member_id
        );
    
    # 獲取當前活動(type_id; 51:首充, 52:每次充, 53:首充, 54:每次充, 55:每日充)
	DECLARE Cur CURSOR FOR 
		SELECT p.id
		FROM prizes AS p 
        JOIN events AS e 
			ON e.id = p.event_id 
            AND e.status = 1 
        JOIN event_details AS ed 
			ON ed.event_id = e.id 
            AND ed.status = 1 
            AND ed.start_time <= NOW() 
            AND ed.end_time >= NOW() 
            AND ed.action_id in (51, 52, 53, 54, 55) 
		JOIN agents AS a
			ON a.id = e.agent_id
            AND HierarchyPath LIKE CONCAT(a.hierarchy_path, '%')
        WHERE p.withdrawal_cancel_reward = 1 
        AND p.status = 1;
	
	DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    OPEN Cur;
	REPEAT 
		FETCH Cur 
        INTO PrizeId;
		IF NOT Done THEN 
        
			# 將現有登入獎勵取消
			UPDATE prize_members 
            SET status = 3 
            WHERE prize_id = PrizeId 
            AND member_id = p_member_id 
            AND status = 0;
            
        END IF;
    UNTIL Done END REPEAT;
    CLOSE Cur;
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
