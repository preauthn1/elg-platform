<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpCalcSlotCompetition extends Migration
{
    protected $procedure_name = 'SP_calc_slot_competition';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(
	IN p_event_id INT,
	IN p_event_detail_id INT
)
BEGIN
	
	# 2020-09-03 Mars 			老虎機爭霸賽統計SP 
    
    DECLARE Now DATETIME DEFAULT NOW();
    DECLARE EventId, EventDetailId INT DEFAULT 0;
    DECLARE SlotCompetitionId, SlotCompetitionNo INT DEFAULT 0;
    
    SELECT id, no
    INTO SlotCompetitionId, SlotCompetitionNo 
    FROM event_slot_competitions
    WHERE event_id = p_event_id
    AND event_detail_id = p_event_detail_id;
    
    #名次重新排名
    UPDATE event_slot_competition_records
    SET ranking = 0
    WHERE event_slot_competition_id = SlotCompetitionId
    AND ranking != 0;
    
    INSERT INTO event_slot_competition_records(
		event_slot_competition_id, member_id, bet_amount, 
        last_bet_time, ranking
	)
    SELECT SlotCompetitionId, z.member_id, z.point, z.last_bet_time, z.rank
    FROM (
		SELECT p.member_id
			, p.point
			, p.last_bet_time
			, @rownum := @rownum + 1 AS rank
		FROM (
			SELECT member_id, point, MAX(last_bet_time) AS last_bet_time
			FROM event_processes
			WHERE detail_id = p_event_detail_id
            GROUP BY member_id
			ORDER BY point DESC, last_bet_time ASC
		) AS p,
		(SELECT @rownum := 0) r
	) AS z
	ON DUPLICATE KEY UPDATE 
		bet_amount = z.point,
		last_bet_time = z.last_bet_time,
        ranking = z.rank;
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
