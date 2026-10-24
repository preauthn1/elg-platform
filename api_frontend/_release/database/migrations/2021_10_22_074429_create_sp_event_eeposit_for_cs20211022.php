<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpEventEepositForCs20211022 extends Migration
{
    protected $procedure_name = 'SP_event_deposit_for_cs';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(IN p_transaction_log_id INT, IN p_role_id INT)
BEGIN
	# 代理/客服開分-充值活動 
    # 2021/10/22 Mars 		ADD 
    
    DECLARE MemberId, PlatformAgentId, AgentId, OrderStatus, OrderID INT;
    DECLARE TransactionLogType INT DEFAULT 19;
    DECLARE BeforeBalance, DepositPoint DECIMAL(18, 4);
    DECLARE RemarkText VARCHAR(20) DEFAULT '首充活動獎勵';
    DECLARE RewardPoint DECIMAL(18, 4) DEFAULT 0; 		# 獎勵金額
    DECLARE FlowLimitRate DECIMAL(10, 2) DEFAULT 0; 		# 稽核流水倍數
    DECLARE Operator VARCHAR(10) DEFAULT 'system';
    DECLARE OperatorId INT DEFAULT 51;
    DECLARE SettingKey INT DEFAULT 6014; 
    DECLARE ReferenceId INT DEFAULT 0;
    DECLARE PrizeId, ActionId, DetailOrderType, OrderTypeId, PointLimit INT DEFAULT 0;
    DECLARE PrizeContentId, PrizeType, PrizeNumber, PrizeSent, PrizePercentage, PrizePoint, PrizeIsRepeatedly INT;
    DECLARE PrizePointPercentage DECIMAL(4, 2) DEFAULT 0.00;
    DECLARE PrizeName VARCHAR(50);
	DECLARE Done, Continues INT DEFAULT 0;
    
	# 取得該筆充值的建立時間
    DECLARE OrderCreatedAt DATETIME DEFAULT (SELECT created_at FROM transaction_logs WHERE id = p_transaction_log_id);

    # 獲取當前活動(type_id; 51:首充, 52:每次充)
	DECLARE CurDate CURSOR FOR 
		SELECT e.agent_id 
			, ed.action_id, ed.order_type_id, ed.point
            , p.id, p.is_repeatedly
		FROM event_details AS ed
		JOIN events AS e ON e.id = ed.event_id AND e.status = 1
        JOIN prizes AS p ON p.event_id = e.id AND p.status = 1
		WHERE ed.action_id IN (51, 52)
		AND ed.status = 1
        AND ed.order_type_id IN (0, -2, -3)  #-2:客服, -3:代理
		AND ed.start_time <= OrderCreatedAt
		AND ed.end_time >= OrderCreatedAt;
        
	DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    OPEN CurDate;
	REPEAT
		FETCH CurDate 
        INTO PlatformAgentId, ActionId, DetailOrderType, PointLimit, PrizeId, PrizeIsRepeatedly;
		IF NOT Done THEN
        
			IF ActionId = 51 THEN
				SET SettingKey = 6014; # 首充稽核流水倍數
                SET TransactionLogType = 19;
			ELSE 
				SET SettingKey = 6012; # 活動稽核流水倍數
                SET TransactionLogType = 24;
			END IF;
            
            SET OrderStatus = 0;
            
			#查訂單
			SELECT m.id AS member_id
				, m.balance
                , a.id AS agent_id
                , o.id
				, o.point
                , o.status
                , IFNULL((acs.value * 1.00), 0) AS flow_limit_rate
			INTO MemberId, BeforeBalance, AgentId, OrderID
				, DepositPoint, OrderStatus, FlowLimitRate
			FROM transaction_logs AS o
			JOIN members AS m 
				ON m.id = o.member_id
			JOIN agents AS a 
				ON a.id = m.parent 
				AND a.hierarchy LIKE CONCAT('%,', PlatformAgentId, ',%')
			LEFT JOIN agent_customized_settings AS acs 
				ON acs.agent_group_id = a.customized_setting_group_id 
				AND setting_key = SettingKey
			WHERE o.id = p_transaction_log_id;
            
            SET OrderTypeId = (p_role_id * -1);
            
			# 判斷訂單是否成立
			IF OrderStatus = 1000 THEN
				# 判斷充值類型
				IF DetailOrderType = 0 OR DetailOrderType = OrderTypeId THEN
					# 判斷訂單金額是否超過限制
					IF PointLimit <= DepositPoint THEN
						# 找出獎勵內容
						SELECT id, type, name, number, sent, percentage, IFNULL(point, 0), IFNULL(point_percentage, 0)
						INTO PrizeContentId, PrizeType, PrizeName, PrizeNumber, PrizeSent, PrizePercentage, PrizePoint, PrizePointPercentage 
						FROM prize_contents 
						WHERE prize_id = PrizeId
						AND status = 1;
						
						SET Continues = 0;
						IF PrizeIsRepeatedly = 1 THEN
							# 查看是否已領過
							IF EXISTS (
								SELECT * FROM prize_members 
								WHERE member_id = MemberId AND prize_id = PrizeId 
								AND lottery_number = OrderID
							) THEN
								SET Continues = 1;
							END IF;
						ELSE
							IF EXISTS (
								SELECT * FROM prize_members 
								WHERE member_id = MemberId AND prize_id = PrizeId 
							) THEN
								SET Continues = 1;
							END IF;
						END IF;
						
						IF Continues = 0 THEN
							# 查看獎項上限
							IF PrizeNumber = -1 OR (PrizeNumber - PrizeSent) > 0 THEN
							
								# 計算獎勵金額
								IF PrizeType = 1 THEN
									# 依照比例
									SET RewardPoint = (DepositPoint * PrizePointPercentage);
								ELSE
									# 固定金額
									SET RewardPoint = PrizePoint;
								END IF;
							
								SET RemarkText = PrizeName;
							
								# 寫入獎勵
								UPDATE members SET balance = (balance + RewardPoint) 
								WHERE id = MemberId;
								
								# 寫入領獎紀錄
								INSERT INTO prize_members(member_id, prize_id, content_id, lottery_point, status, admin)
								VALUES(MemberId, PrizeId, PrizeContentId, RewardPoint, 1, OperatorId);
				
								# 寫入transaction_logs
								INSERT INTO transaction_logs(transaction_no, agent_id, member_id, vendor_id, order_id
									, type, point, vendor_point, rate
									, before_balance, balance, vendor_balance
									, status, remark, result, operator, platform, admin)
								VALUES(OrderID, AgentId, MemberId, 0, 0
									, TransactionLogType, RewardPoint, 0, 1
									, BeforeBalance, (BeforeBalance + RewardPoint), 0
									, 1000, RemarkText, 0, Operator, 0, 0);
							
								# 取得transaction_logs.id
								SELECT LAST_INSERT_ID() INTO ReferenceId;
							
								# 寫入稽核流水
								IF FlowLimitRate > 0 THEN
									INSERT INTO flow_limits(member_id, type, reference_id, flow_limit, admin)
									VALUES(MemberId, TransactionLogType, ReferenceId, (RewardPoint * FlowLimitRate), OperatorId);
								END IF;
								
								#更新獎品派發數量
								UPDATE prize_contents
								SET sent = (sent + 1)
								WHERE id = PrizeContentId;
							END IF;
						END IF;
					END IF;
                END IF;
            END IF;
        END IF;
    UNTIL Done END REPEAT;
    CLOSE CurDate;
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
