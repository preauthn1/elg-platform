<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifySpSummaryEventRecordByLottery20210514 extends Migration
{
    protected $procedure_name = 'SP_summary_event_record_by_lottery';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `" . $this->procedure_name . "`(IN p_StartTime DATETIME, IN p_EndTime DATETIME)
BEGIN

	# 彙整transaction_log, 獎勵(13, 15, 20, 21) 
    
    # 一般呼叫方式: CALL SP_summary_event_record_by_lottery(NULL, NULL);
    # 重算呼叫方式: CALL SP_summary_event_record_by_lottery('2020-05-30 10:00:00', '2020-05-30 12:00:00');
    /*
    新增Event
	CREATE EVENT Event_Summary_event_reocrd_by_lottery
		ON SCHEDULE EVERY 1 HOUR STARTS '2021-05-01 00:00:00' #系統時間UTC+0
		COMMENT '每小時-彙整V-world獎勵'
		DO
			CALL SP_summary_event_record_by_lottery(NULL, NULL);
    */
    
    DECLARE EventId INT DEFAULT 1;
    
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
		SELECT z.summary_time, z.member_id, SUM(z.point) as point
		FROM (
			SELECT member_id
				, point
				, DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS summary_time
			FROM transaction_logs  
			WHERE created_at >= p_StartTime
			AND created_at < p_EndTime
			AND type IN (13, 15, 20, 21) 
		) AS z
		GROUP BY z.summary_time, z.member_id
	) AS y
	ON DUPLICATE KEY 
    UPDATE point = IFNULL(y.point, 0);
    
    #agetn
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
