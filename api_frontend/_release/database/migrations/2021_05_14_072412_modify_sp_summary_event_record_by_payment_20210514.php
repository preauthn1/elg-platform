<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifySpSummaryEventRecordByPayment20210514 extends Migration
{
    protected $procedure_name = 'SP_summary_event_record_by_payment';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `" . $this->procedure_name . "`(IN p_StartTime DATETIME, IN p_EndTime DATETIME)
BEGIN

	# 彙整支付成功成本計算
    
    # 一般呼叫方式: CALL SP_summary_event_record_by_payment(NULL, NULL);
    # 重算呼叫方式: CALL SP_summary_event_record_by_payment('2020-05-30 10:00:00', '2020-05-30 12:00:00');
    /*
    新增Event
	CREATE EVENT Event_Summary_event_reocrd_by_sms
		ON SCHEDULE EVERY 1 HOUR STARTS '2021-05-01 00:00:00' #系統時間UTC+0
		COMMENT '每小時-彙整支付成本'
		DO
			CALL SP_summary_event_record_by_payment(NULL, NULL);
    */
    DECLARE EventId INT DEFAULT 3;
    
	IF p_StartTime IS NULL THEN
		SET p_StartTime = DATE_SUB(NOW(), INTERVAL 3 HOUR);
    END IF;
    
	IF p_EndTime IS NULL THEN
		SET p_EndTime = DATE_ADD(p_StartTime, INTERVAL 2 HOUR);
    END IF;
    
    # member
    INSERT INTO summary_event_records(summary_time, member_id, event_id, point)
    SELECT y.summary_time, y.member_id, EventId, y.point
    FROM (
		SELECT r.summary_time
			, m.id as member_id
			, TRUNCATE(SUM(acf.cost * r.amount), 4) AS point
		FROM (
			SELECT member_id
				, agent_cash_flow_id
				, SUM(amount) AS amount
				, DATE_FORMAT(pay_time, '%Y-%m-%d %H:00:00') AS summary_time
			FROM orders  
			WHERE pay_time >= '2021-05-14 06:00:00'
			AND pay_time < '2021-05-14 07:00:00'
			AND status = 3 
            GROUP BY member_id, agent_cash_flow_id
		) AS r
        JOIN members AS m ON m.id = r.member_id
		JOIN agents AS a ON a.id = m.parent
		JOIN agent_cash_flows AS acf ON acf.id = r.agent_cash_flow_id
		GROUP BY r.summary_time, m.id having point > 0
	) AS y
	ON DUPLICATE KEY 
    UPDATE point = IFNULL(y.point, 0);
    
    # agent
    INSERT INTO summary_event_record_agents(summary_time, agent_id, event_id, point)
	SELECT y.summary_time, y.agent_id, y.event_id, y.point
    FROM (
		SELECT r.summary_time, a.id as agent_id, r.event_id, sum(r.point) as point
		FROM summary_event_records as r
		JOIN members as m on m.id = r.member_id
		JOIN agents as a on a.id = m.parent
		WHERE summary_time >= p_StartTime
		AND summary_time < p_EndTime
		AND r.event_id = EventId
		GROUP BY r.summary_time, a.id
    ) AS y
	ON DUPLICATE KEY 
    UPDATE point = IFNULL(y.point, 0);
END";
        DB::unprepared('DROP procedure IF EXISTS ' . $this->procedure_name . '');
        DB::unprepared($procedure);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::unprepared('DROP procedure IF EXISTS ' . $this->procedure_name . '');
    }
}
