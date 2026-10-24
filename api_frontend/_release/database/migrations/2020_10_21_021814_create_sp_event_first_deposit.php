<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpEventFirstDeposit extends Migration
{

    protected $procedure_name = 'SP_event_first_deposit';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {

        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(IN p_order_id INT)
BEGIN
	# 首儲活動 
    # 2020/10/14 Mars 		增加首儲活動
    
    DECLARE MemberId, PlatformAgentId, AgentId, OrderStatus, AgentCashFlowId, OrderID INT;
    DECLARE MemberCreatedAt, StartTime, EndTime DATETIME;
    DECLARE TransactionLogType INT DEFAULT 19;
    DECLARE BeforeBalance, DepositPoint DECIMAL(18, 4);
    DECLARE FirstDeposit VARCHAR(20);
    DECLARE RemarkText VARCHAR(20) DEFAULT '首充活動獎勵';
    DECLARE RewardMultiple DECIMAL(3, 2) DEFAULT 0.20; 		# 獎勵倍數
    DECLARE RewardPoint DECIMAL(18, 4) DEFAULT 0; 		# 獎勵金額
    DECLARE Operator VARCHAR(10) DEFAULT 'system';
    
	# 查首充活動(type_id = 6)
	SELECT start_time, end_time, e.agent_id 
    INTO StartTime, EndTime, PlatformAgentId
	FROM event_details AS ed
	JOIN events AS e ON e.id = ed.event_id AND e.status = 1
    WHERE ed.action_id = 6
    AND ed.status = 1
    AND start_time <= NOW()
    AND end_time > NOW();
    IF PlatformAgentId > 0 THEN
        # 查訂單資訊
		SELECT m.id as member_id, m.balance, m.created_at, a.id as agent_id, o.id, o.point, o.status
				, ifnull(m.first_deposit, '') as first_deposit, o.agent_cash_flow_id
		INTO MemberId, BeforeBalance, MemberCreatedAt, AgentId, OrderID, DepositPoint, OrderStatus, FirstDeposit, AgentCashFlowId
		FROM orders as o
		JOIN members as m on m.id = o.member_id
		JOIN agents as a on a.id = m.parent and a.hierarchy like CONCAT('%,', PlatformAgentId, ',%')
		WHERE o.id = p_order_id;
    
		# 判斷訂單是否成立
		IF OrderStatus = 3 AND FirstDeposit = '' THEN
			# 計算獎勵金額
            SET RewardPoint = (DepositPoint * RewardMultiple);
            
			# 首存欄位寫入當前時間
			UPDATE members set balance = balance + RewardPoint, first_deposit = NOW() WHERE id = MemberId;
            
			# 寫入transaction_logs
			INSERT INTO transaction_logs(transaction_no, agent_id, member_id, vendor_id, order_id
				, type, point, vendor_point, rate
				, before_balance, balance, vendor_balance
				, status, remark, result, operator, platform, admin)
			VALUES(OrderID, AgentId, MemberId, 0, 0
				, TransactionLogType, RewardPoint, 0, 1
				, BeforeBalance, (BeforeBalance + RewardPoint), 0
				, 1000, RemarkText, 0, Operator, 0, 0);
		END IF;
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
