<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpPayoutSlotCompetitionSub extends Migration
{
    protected $procedure_name = 'SP_payout_slot_competition_sub';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(
	IN p_slot_competition_id INT,
    IN p_event_id INT,
    IN p_event_detail_id INT,
    IN p_slot_competition_no INT
)
BEGIN

	# 派發老虎機爭霸戰獎項
    # 2022/05/23 Mars 		新增
    
	DECLARE Done INT DEFAULT 0;
    DECLARE PrizeId, PrizeContentId, PrizeContentNumber, PrizeContentLevel INT(11);
    DECLARE PrizeContentPoint DECIMAL(18, 4);
    DECLARE PrizeContentName VARCHAR(50);
    
	# 取出獎項
	DECLARE cur CURSOR FOR 
    SELECT p.id, pc.id, pc.name, pc.level, pc.number, pc.point
	FROM prizes AS p
	JOIN prize_contents AS pc ON pc.prize_id = p.id AND pc.status = 1
	WHERE event_id = p_event_id
	AND p.status = 1
    ORDER BY pc.level ASC;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    
	OPEN cur;
    REPEAT
		FETCH cur 
        INTO PrizeId, PrizeContentId, PrizeContentName, PrizeContentLevel, PrizeContentNumber, PrizeContentPoint;
		IF NOT Done THEN
			
            UPDATE event_slot_competition_records
            SET type = 1, 
				prize_content_id = PrizeContentId,
                point = PrizeContentPoint
			WHERE event_slot_competition_id = p_slot_competition_id
            AND type = 0
			ORDER BY ranking ASC
			LIMIT PrizeContentNumber;
            
            /*
            #領取時才寫入
            INSERT INTO prize_members(
				member_id, prize_id, content_id, lottery_point, status, admin
            )
			SELECT member_id, PrizeId, PrizeContentId, PrizeContentPoint, 0, 0 
            FROM event_slot_competition_records
            WHERE event_slot_competition_id = p_slot_competition_id
            AND prize_content_id = PrizeContentId;
            */

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
