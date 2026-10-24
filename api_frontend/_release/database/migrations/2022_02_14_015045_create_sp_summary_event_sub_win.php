<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpSummaryEventSubWin extends Migration
{
    protected $procedure_name = 'SP_summary_event_sub_win';

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
	# 活動獎勵派發 - win
    # 2022/02/08 Mars 		ADD 
    
    DECLARE AgentHierarchyPath VARCHAR(50) DEFAULT (SELECT hierarchy_path FROM agents WHERE id = p_agent_id);
    DECLARE VendorId INT DEFAULT IFNULL(p_vendor_id, 0);
    DECLARE GameTypeId INT DEFAULT IFNULL(p_game_type_id, 0);
    DECLARE JoinEventUsed INT DEFAULT IFNULL(p_join_event_used, 0);
    DECLARE EventStartTime DATETIME DEFAULT p_event_start_time;
    DECLARE EventEndTime DATETIME DEFAULT p_event_end_time;
    DECLARE CurMemberId, CurMemberStatus INT;
    DECLARE CurMemberPoint DECIMAL(18, 4);
	DECLARE Done, Continues INT DEFAULT 0;
    
    # 勝分 只統計有參加活動的會員
    DECLARE Cur CURSOR FOR 
        SELECT t.member_id, SUM(t.bet) AS point
        FROM (
			SELECT r.member_id, r.vendor_id, r.game_id, r.bet
            FROM (
				SELECT m.id AS member_id,
					a.id AS agent_id,
                    v.id AS vendor_id,
                    r.game_id,
                    a.hierarchy_path,
                    SUM(r.bet) AS bet
				FROM vendor_hour_records AS r
                JOIN members AS m ON m.id = r.member_id
                JOIN agents AS a ON a.id = m.parent AND a.hierarchy_path LIKE CONCAT(AgentHierarchyPath, '%')
                JOIN vendor AS v 
					ON v.id = r.vendor_id 
                    # 舊資料沒有GAME ID，但是是有統計過的，所以可以直接加入
					AND (VendorId = 0 OR (VendorId > 0 AND v.id = VendorId))
				LEFT JOIN games AS g ON g.id = r.game_id AND (GameTypeId = 0 OR (GameTypeId > 0 AND g.type_id = GameTypeId))
                WHERE r.date_hour BETWEEN EventStartTime AND EventEndTime
                AND (
					(JoinEventUsed = 0 AND v.valid_win_rate > 0) 
                    OR 
                    (JoinEventUsed = 1 AND g.id IS NOT NULL)
				)
                GROUP BY m.id, v.id, r.game_id
                HAVING bet > 0
            ) AS r
            JOIN (
				SELECT av.vendor_id,
					a.hierarchy_path,
                    av.vendor_info_id,
                    LOCATE(CONCAT('/', av.agent_id), a.hierarchy_path) AS indexs
                FROM agent_vendors AS av
                JOIN agents AS a ON a.id = av.agent_id AND a.host_id = 0 AND a.hierarchy_path LIKE CONCAT(AgentHierarchyPath, '%')
                WHERE av.status = 1
            ) AS av ON av.vendor_id, r.vendor_id
            LEFT JOIN vendor_info_games AS vig ON vig.game_id = r.game_id AND vig.vendor_info_id = av.vendor_info_id
			WHERE (
				(JoinEventUsed = 1 AND vig.join_win_event = 1)
                OR
                # 排除對壓遊戲
                (JoinEventUsed = 0 AND (vig.valid_win_rate IS NULL OR vig.valid_win_rate > 0))
            )
            AND r.hierarchy_path LIKE CONCAT(AV.hierarchy_path, '%')
            GROUP BY r.member_id, r.vendor_id, r.game_id, av.vendor_info_id
            HAVING max(av.indexs)
        ) AS t
        GROUP BY t.member_id
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
