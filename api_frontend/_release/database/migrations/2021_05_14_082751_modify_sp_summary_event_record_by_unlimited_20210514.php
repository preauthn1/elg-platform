<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifySpSummaryEventRecordByUnlimited20210514 extends Migration
{
    protected $procedure_name = 'SP_summary_event_record_by_unlimited';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `" . $this->procedure_name . "`(IN p_DateTime DATETIME)
BEGIN
	# 彙整無限代獎勵
    
    # 一般呼叫方式: CALL SP_summary_event_record_by_unlimited(NULL);
    # 重算呼叫方式: CALL SP_summary_event_record_by_unlimited('2020-05-30 10:00:00');
	# 全民代理派發完直接計算
    
    DECLARE EventId INT DEFAULT 2;
    DECLARE SummaryTime, CurrentTime, StartTime, EndTime DATETIME;
    
	SET CurrentTime = DATE_ADD(IFNULL(p_DateTime, NOW()), INTERVAL 8 HOUR);
	SET SummaryTime = DATE(CurrentTime);
    SET StartTime = DATE_SUB(DATE(CurrentTime), INTERVAL 8 HOUR);
    SET EndTime = DATE_ADD(DATE(CurrentTime), INTERVAL 16 HOUR);

    # member
    INSERT INTO summary_event_records(summary_time, member_id, event_id, point)
    SELECT y.summary_time, y.member_id, EventId, y.point
    FROM (
		SELECT SummaryTime AS summary_time, z.member_id, SUM(z.point) AS point
		FROM (
			select member_id
					, TRUNCATE(bonus_direct, 2) + TRUNCATE(bonus_team, 2) AS point
					, DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS summary_time
			from unlimit_bonusreport
			where summary_date >= DATE_SUB(StartTime, INTERVAL 1 DAY)
			and summary_date < DATE_SUB(EndTime, INTERVAL 1 DAY)
			and (
				TRUNCATE(bonus_direct, 2) > 0 
                OR TRUNCATE(bonus_team, 2) > 0
			)
		) AS z
		GROUP BY z.member_id
	) AS y
	ON DUPLICATE KEY 
    UPDATE point = IFNULL(y.point, 0);
    
    # agetn
    INSERT INTO summary_event_record_agents(summary_time, agent_id, event_id, point, self_point)
	SELECT y.summary_time, y.agent_id, EventId, y.point, y.self_point
    FROM (
		SELECT SummaryTime AS summary_time, z.agent_id, SUM(z.point) AS point, SUM(z.self_point) AS self_point
        FROM (
			(
				# 該代理獎勵
				SELECT ua.agent_id
						, 0 AS point
						, TRUNCATE(ua.bonus_direct, 2) + TRUNCATE(ua.bonus_team, 2) AS self_point
				FROM unlimit_bonusreport_agents AS ua
				JOIN agents AS a ON a.id = ua.agent_id 
				WHERE ua.summary_date >= DATE_SUB(StartTime, INTERVAL 1 DAY)
				AND ua.summary_date < DATE_SUB(EndTime, INTERVAL 1 DAY)
				AND (
					TRUNCATE(ua.bonus_direct, 2) > 0 
                    OR TRUNCATE(ua.bonus_team, 2) > 0
				)
				GROUP BY ua.agent_id
			) UNION ALL (
				# 該代理下層玩家獎勵
				SELECT a.id AS agent_id
						, sum(r.point) AS point
						, 0 AS self_point
				FROM summary_event_records AS r
				JOIN members AS m ON m.id = r.member_id
				JOIN agents AS a ON a.id = m.parent 
				WHERE r.summary_time >= StartTime
				AND r.summary_time < EndTime
				AND r.event_id = EventId
				GROUP BY a.id
			)  
		) AS z
        GROUP BY z.agent_id
    ) AS y
	ON DUPLICATE KEY 
    UPDATE point = IFNULL(y.point, 0)
		, self_point = IFNULL(y.self_point, 0);
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
