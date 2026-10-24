<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpSummaryEventSubDeposit extends Migration
{
    protected $procedure_name = 'SP_summary_event_sub_deposit';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(
	IN p_event_id INT,
    IN p_event_detail_id INT,
    IN p_action_id INT,
    IN p_order_type_id INT,
    IN p_vendor_id INT,
    IN p_game_type_id INT,
    IN p_game_id INT,
    IN p_join_event_used INT,
    IN p_rate DECIMAL(8, 4),
    IN p_point DECIMAL(18, 4),
    IN p_event_start_time DATETIME,
    IN p_event_end_time DATETIME,
	IN p_agent_id INT
)
BEGIN
	# 活動獎勵派發 - deposit
    # 2022/02/08 Mars 		ADD 
	
    DECLARE AgentHierarchyPath VARCHAR(50) DEFAULT (SELECT hierarchy_path FROM agents WHERE id = p_agent_id);
    DECLARE EventStartTime DATETIME DEFAULT p_event_start_time;
    DECLARE EventEndTime DATETIME DEFAULT p_event_end_time;
    DECLARE OrderTypeId INT DEFAULT IFNULL(p_order_type_id, 0);
    DECLARE CurMemberId, CurMemberStatus INT;
    DECLARE CurMemberPoint DECIMAL(18, 4);
	DECLARE Done, Continues INT DEFAULT 0;
    
    # 儲值
    DECLARE Cur CURSOR FOR 
        SELECT t.member_id, SUM(r.point) AS point
		FROM orders AS r
		JOIN members AS m ON m.id = r.member_id
		JOIN agents AS a ON a.id = m.parent AND a.hierarchy_path LIKE CONCAT(AgentHierarchyPath, '%')
		WHERE r.updated_at BETWEEN EventStartTime AND EventEndTime
		AND r.status = 1
		AND (OrderTypeId = 0 OR (OrderTypeId > 0 AND r.order_type_id = OrderTypeId))
		GROUP BY r.member_id
		HAVING point > 0;
        
	DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    OPEN Cur;
	REPEAT 
		FETCH Cur 
        INTO CurMemberId, CurMemberPoint;
		IF NOT Done THEN 
			IF CurMemberPoint >= p_point THEN
				SET CurMemberStatus = 1;
			ELSE
				SET CurMemberStatus = 0;
            END IF;
            
            CALL SP_summary_event_add_process(
				CurMemberId,
                p_event_detail_id,
                CurMemberPoint,
                CurMemberStatus
			);
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
