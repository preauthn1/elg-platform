<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifySpEventDeposit20211123 extends Migration
{
    protected $procedure_name = 'SP_event_deposit';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(IN p_order_id INT)
BEGIN
	# 充值活動 
    # 2021/10/06 Mars 		ADD 
    # 2021/10/22 Mars 		獎勵金額寫到prize_members.lottery_point
	# 						每次充獎勵的流水倍數改用活動稽核流水倍數(6012)
	# 2021/11/19 Mars 		Fix 當查出活動有一個不是當前代理線, SP就會直接結束的問題
	# 2021/11/23 Mars 		支援手動領獎
    
    DECLARE MemberId, PlatformAgentId, AgentId, OrderStatus, EventDetailId INT;
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
	# 取得該筆訂單的建立時間
    DECLARE OrderCreatedAt DATETIME DEFAULT (SELECT created_at FROM orders WHERE id = p_order_id);

    # 獲取當前活動(type_id; 51:首充, 52:每次充, 53:首充, 54:每次充)
	DECLARE CurDate CURSOR FOR 
		SELECT ed.id, e.agent_id 
			, ed.action_id, ed.order_type_id, ed.point
            , p.id, p.is_repeatedly
		FROM event_details AS ed
		JOIN events AS e ON e.id = ed.event_id AND e.status = 1
        JOIN prizes AS p ON p.event_id = e.id AND p.status = 1
		WHERE ed.action_id IN (51, 52, 53, 54)
		AND ed.status = 1
		AND ed.order_type_id >= 0
		AND ed.start_time <= OrderCreatedAt
		AND ed.end_time >= OrderCreatedAt;
        
	DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    OPEN CurDate;
	REPEAT
		FETCH CurDate INTO EventDetailId, PlatformAgentId, ActionId, DetailOrderType, PointLimit, PrizeId, PrizeIsRepeatedly;
		IF NOT Done THEN
			IF ActionId = 51 OR ActionId = 53 THEN
				SET SettingKey = 6014; # 首充稽核流水倍數
                SET TransactionLogType = 19;
			ELSE 
				SET SettingKey = 6012; # 活動稽核流水倍數
                SET TransactionLogType = 24;
			END IF;
            
            SET OrderStatus = 0;
            
			# 查訂單
            SELECT o.member_id, o.point, o.status, o.order_type_id, m.parent, m.balance 
            INTO MemberId, DepositPoint, OrderStatus, OrderTypeId, AgentId, BeforeBalance
            FROM orders AS o 
            JOIN members AS m ON m.id = o.member_id
            WHERE o.id = p_order_id;
            
            # 檢查此活動是否為此代理線
            IF EXISTS (SELECT * FROM agents WHERE id = AgentId AND hierarchy LIKE CONCAT('%,', PlatformAgentId, ',%')) THEN
				
                # 取得稽核流水倍率
                SELECT IFNULL(IFNULL(acs.value, asd.default_value) * 1.00, 0) AS flow_limit_rate
                INTO FlowLimitRate
                FROM agent_setting_defines AS asd
                LEFT JOIN agent_customized_settings AS acs ON acs.setting_key = asd.setting_id
                JOIN agents as a on a.customized_setting_group_id = acs.agent_group_id AND a.id = AgentId
                WHERE asd.setting_id = SettingKey;
                
				# 判斷訂單是否成立
				IF OrderStatus = 3 THEN
					# 判斷充值類型
					IF DetailOrderType = 0 OR DetailOrderType = OrderTypeId THEN
						# 判斷訂單金額是否超過限制
						IF PointLimit <= DepositPoint THEN
							/*
							# 計算percentage
							SELECT floor(rand() * SUM(percentage)), COUNT(*)
							INTO @Percentage, PrizeCount
							FROM prize_contents 
							WHERE prize_id = PrizeId
							AND status = 1
							AND (number = -1 OR (number - sent) > 0);
						
							# 找出獎勵內容
							IF PrizeCount > 0 THEN
								SELECT @Percentage, z.rownum, (z.rownum >= @Percentage), z.id, z.type, z.name, z.number, z.sent, z.percentage, IFNULL(z.point, 0), IFNULL(z.point_percentage, 0)
								FROM (
									SELECT @Percentage, (@i:= @i + p.percentage) as rownum, 
											p.id, p.type, p.name, p.number, p.sent, p.percentage, IFNULL(p.point, 0) as point, IFNULL(p.point_percentage, 0) as point_percentage
									#INTO PrizeContentId, PrizeType, PrizeName, PrizeNumber, PrizeSent, PrizePercentage, PrizePoint, PrizePointPercentage 
									FROM prize_contents as p
									,(select @i:=0) as r
									WHERE prize_id = PrizeId
									AND status = 1
								) AS z
								WHERE z.rownum >= @Percentage
								limit 1;
							ELSEIF PrizeCount = 1 THEN
                        
							END IF;
							*/
                    
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
									AND lottery_number = p_order_id
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
									SET RemarkText = PrizeName;
                                    
									# 寫入派獎紀錄; 派獎狀態0:未達標1:已達標2:已派獎
									IF ActionId = 51 OR ActionId = 52 THEN
										# 計算獎勵金額
										IF PrizeType = 1 THEN
											# 依照比例
											SET RewardPoint = (DepositPoint * PrizePointPercentage);
										ELSE
											# 固定金額
											SET RewardPoint = PrizePoint;
										END IF;
                                    
										# 寫入派獎紀錄; 派獎狀態0:未達標1:已達標2:已派獎
										IF EXISTS (SELECT * FROM event_processes WHERE detail_id = EventDetailId AND member_id = MemberId) THEN
											UPDATE event_processes
											SET point = (point + PointLimit), received = (received + PointLimit)
											WHERE detail_id = EventDetailId AND member_id = MemberId;
										ELSE
											INSERT INTO event_processes(detail_id, member_id, point, received, status, admin)
											VALUES(EventDetailId, MemberId, PointLimit, PointLimit, 2, OperatorId);
										END IF;
                                    
										# 寫入領獎紀錄
										INSERT INTO prize_members(member_id, prize_id, content_id, lottery_point, status, admin)
										VALUES(MemberId, PrizeId, PrizeContentId, RewardPoint, 1, OperatorId);
									ELSE 
										# 計算獎勵金額
										IF PrizeType = 1 THEN
											# 依照比例
											SET RewardPoint = DepositPoint;
										ELSE
											# 固定金額
											SET RewardPoint = PrizePoint;
										END IF;
                                        
										# 寫入派獎紀錄; 派獎狀態0:未達標1:已達標2:已派獎
										IF EXISTS (SELECT * FROM event_processes WHERE detail_id = EventDetailId AND member_id = MemberId) THEN
											UPDATE event_processes
											SET point = (point + PointLimit) 
											WHERE detail_id = EventDetailId AND member_id = MemberId;
										ELSE
											INSERT INTO event_processes(detail_id, member_id, point, status, admin)
											VALUES(EventDetailId, MemberId, PointLimit, 0, OperatorId);
										END IF;
                                    
										# 寫入領獎紀錄
										INSERT INTO prize_members(member_id, prize_id, lottery_point, status, admin)
										VALUES(MemberId, PrizeId, RewardPoint, 0, OperatorId);
									END IF;
                                
									IF ActionId = 51 OR ActionId = 52 THEN
										# 寫入獎勵
										UPDATE members SET balance = (balance + RewardPoint) 
										WHERE id = MemberId;
                
										# 寫入transaction_logs
										INSERT INTO transaction_logs(transaction_no, agent_id, member_id, vendor_id, order_id
											, type, point, vendor_point, rate
											, before_balance, balance, vendor_balance
											, status, remark, result, operator, platform, admin)
										VALUES(p_order_id, AgentId, MemberId, 0, 0
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
