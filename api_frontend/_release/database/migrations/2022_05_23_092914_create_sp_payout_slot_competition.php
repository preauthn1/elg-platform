<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpPayoutSlotCompetition extends Migration
{
    protected $procedure_name = 'SP_payout_slot_competition';

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

	# 派發老虎機爭霸戰獎項
    # 2022/05/19 Mars 		新增

	DECLARE Done, Done2 INT DEFAULT 0;
    DECLARE StartTime, EndTime DateTime DEFAULT NOW();
    DECLARE EventId, EventDetailId, SlotCompetitionId, SlotCompetitionNo INT DEFAULT 0;

	# 取出開獎期數
    DECLARE cur CURSOR FOR 
    SELECT id, event_id, event_detail_id, no
	FROM event_slot_competitions
	WHERE (
		draw_time = DATE_ADD(CURDATE(), INTERVAL HOUR(NOW()) HOUR)
		OR 
        (event_id = p_event_id AND event_detail_id = p_event_detail_id)
	)
	AND status = 0;
	
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    
	OPEN cur;
    REPEAT
		FETCH cur 
        INTO SlotCompetitionId, EventId, EventDetailId, SlotCompetitionNo;
		IF NOT Done THEN
        
			# 將該期修改為派發中
			UPDATE event_slot_competitions SET status = 2 
            WHERE id = SlotCompetitionId
            AND status = 0;
            
            CALL SP_payout_slot_competition_sub(
				SlotCompetitionId,
                EventId,
                EventDetailId,
                SlotCompetitionNo
			);
            
            # 將該期修改為已派發
			UPDATE event_slot_competitions SET status = 1 
            WHERE id = SlotCompetitionId
            AND status = 2;
		END IF;
	UNTIL Done END REPEAT;
    CLOSE cur;
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
