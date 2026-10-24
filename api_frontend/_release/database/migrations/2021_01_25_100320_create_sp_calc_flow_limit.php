<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpCalcFlowLimit extends Migration
{
    protected $procedure_name = 'SP_calc_flow_limit';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`()
BEGIN
	# 稽核流水計算
	#
    # 2021-01-28 Mars 			Add
    # 2021-02-02 Mars 			Add flow_limit_details
	
    DECLARE p_ChatId INT DEFAULT -452604221;
    DECLARE p_TGMessage VARCHAR(2000); 
    
	DECLARE p_ExecStart DATETIME DEFAULT NOW();
	DECLARE p_ExecEnd DATETIME;
    
	# 取出本次total_bet_records執行的ID區間
	DECLARE p_MinTotalBetRecordId INT 
	DEFAULT (
		SELECT IFNULL(MAX(stat_end_id) + 1, 1) 
        FROM flow_limit_control_logs
        ORDER BY id DESC
        limit 5
	);
        
	DECLARE p_MaxTotalBetRecordId INT 
	DEFAULT (
		SELECT MAX(id) 
		FROM total_bet_records
		WHERE id >= p_MinTotalBetRecordId
	);
    
    Block1: BEGIN
	DECLARE p_Done INT DEFAULT 0;
	DECLARE p_MemberId INT;
	DECLARE p_Bet, p_ValidBetRate DECIMAL(18,4);

	# 抓出當前有效流水
	DECLARE cur CURSOR FOR 
	SELECT Z.member_id 
				, IFNULL(SUM(Z.bet * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0)), 0) AS bet
				, IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0) as valid_bet_rate
			FROM (
					SELECT T.member_id
							, T.vendor_id
                            , T.game_id
                            , T.bet
                            , AV.vendor_info_id
                            , A.hierarchy_path
					FROM (
						SELECT R.vendor_id
								, R.game_id
                                , R.member_id
								, SUM(R.bet) AS bet
						FROM total_bet_records AS R
                        WHERE R.id BETWEEN p_MinTotalBetRecordId AND p_MaxTotalBetRecordId
						GROUP BY R.vendor_id, R.game_id, R.member_id
					) AS T
					JOIN members AS M ON M.id = T.member_id
						AND M.id IN (SELECT member_id FROM flow_limits WHERE status = 0 GROUP BY member_id)
					JOIN agents AS A ON A.id = M.parent
					JOIN (
						SELECT av.agent_id
								, av.vendor_id
								, a.hierarchy_path
								, av.vendor_info_id
								, LOCATE(CONCAT('/', av.agent_id), a.hierarchy_path) AS indexs
						FROM agent_vendors AS av
						JOIN agents AS a ON a.id = av.agent_id AND a.host_id = 0
						WHERE av.status = 1
					) AS AV ON AV.vendor_id = T.vendor_id AND A.hierarchy_path LIKE CONCAT(AV.hierarchy_path, '%')
					GROUP BY T.member_id, T.game_id HAVING MAX(AV.indexs)
				) AS Z
                JOIN vendors AS V ON V.id = Z.vendor_id
                LEFT JOIN vendor_info_games AS VIG ON VIG.game_id = Z.game_id 
					AND VIG.vendor_info_id = Z.vendor_info_id
                GROUP BY Z.member_id 
                HAVING valid_bet_rate > 0
                ORDER BY Z.member_id;
                
    # 監控當not found時, 就跳出
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_Done = 1; 
    
    # 監控當SQLEXCEPTION時, 就ROLLBACK, 並發TG通知
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
		ROLLBACK;
        
        # 發通知
		SET p_TGMessage = '[系統通知] 稽核流水異常';
        INSERT INTO telegram_messages(chat_id, message, status, admin)
        VALUES(p_ChatId, p_TGMessage, 0, 0);
    END;
    
	OPEN cur;
	REPEAT
	FETCH cur INTO p_MemberId, p_Bet, p_ValidBetRate;
		Block2: BEGIN
		DECLARE p_Done2 INT DEFAULT 0;
		DECLARE p_FID, p_FMember_id INT;
		DECLARE p_FlowLimit, p_Reach DECIMAL(18,4);
        
		DECLARE cur1 CURSOR FOR 
			SELECT id, member_id, flow_limit, reach 
			FROM flow_limits 
			WHERE member_id = p_MemberId
            AND status = 0
			ORDER BY id;
		DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_Done2 = 1;
        
		OPEN cur1;
		REPEAT
		FETCH cur1 INTO p_FID, p_FMember_id, p_FlowLimit, p_Reach;
			# 當有效流水為0 離開
			IF p_Bet <= 0 THEN
				LEAVE Block2;
			ELSE
                IF p_Bet > (p_FlowLimit - p_Reach) THEN
					UPDATE flow_limits
                    SET reach = flow_limit,
						status = 1
                    WHERE id = p_FID;
                    
                    INSERT INTO flow_limit_details(flow_limit_id, start_total_bet_record_id, end_total_bet_record_id) 
                    SELECT p_FID
							, p_MinTotalBetRecordId AS start_total_bet_record_id
                            , p_MaxTotalBetRecordId AS end_total_bet_record_id 
					FROM flow_limits
					WHERE id = p_FID 
					ON DUPLICATE KEY UPDATE end_total_bet_record_id = p_MaxTotalBetRecordId;
                    
                    SET p_Bet = (p_Bet - (p_FlowLimit - p_Reach));
                ELSE 
					UPDATE flow_limits
                    SET reach = (reach + p_Bet)
                    WHERE id = p_FID;
                    
                    INSERT INTO flow_limit_details(flow_limit_id, start_total_bet_record_id, end_total_bet_record_id) 
                    SELECT p_FID
							, p_MinTotalBetRecordId AS start_total_bet_record_id
                            , p_MaxTotalBetRecordId AS end_total_bet_record_id 
					FROM flow_limits
					WHERE id = p_FID 
					ON DUPLICATE KEY UPDATE end_total_bet_record_id = p_MaxTotalBetRecordId;
                    
                    SET p_Bet = 0;
                END IF;
			END IF;
            
		UNTIL p_Done2 END REPEAT;
		CLOSE cur1;
		END Block2;
	UNTIL p_Done END REPEAT;
	CLOSE cur;
    END Block1;
    
    IF p_MinTotalBetRecordId <= p_MaxTotalBetRecordId THEN
		# 寫紀錄
		SET p_ExecEnd = NOW();
		INSERT INTO flow_limit_control_logs(stat_start_id, stat_end_id, exec_start_time, exec_end_time)
		VALUES(p_MinTotalBetRecordId, p_MaxTotalBetRecordId, p_ExecStart, p_ExecEnd);
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
