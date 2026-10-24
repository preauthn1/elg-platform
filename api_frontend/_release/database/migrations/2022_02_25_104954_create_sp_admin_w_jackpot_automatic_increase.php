<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpAdminWJackpotAutomaticIncrease extends Migration
{
    protected $procedure_name = 'ADMIN_wjackpot_automatic_increase';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(IN p_event_id INT)
BEGIN
	/*
    CREATE EVENT Event_WJackpot_Automatic_Increase_wintoto
	ON SCHEDULE EVERY 1 DAY STARTS '2022-02-15 12:00:00' #系統時間UTC+0
    COMMENT '每天 - WJackpot自動增加'
    DO
		CALL ADMIN_wjackpot_automatic_increase(204);
    */
    
    DECLARE EventId INT DEFAULT IFNULL(p_event_id, 0);
    DECLARE EventDetailId INT;
    DECLARE EventDetailCountLimit INT DEFAULT 5;
    DECLARE EventDetailCount INT DEFAULT 0;
	DECLARE Done INT DEFAULT 0;
    
	DECLARE CurDate CURSOR FOR 
		SELECT e.id 
        FROM event_details AS ed
        JOIN events AS e ON e.id = ed.event_id AND e.status = 1
		WHERE ed.action_id = 2 AND ed.game_join_event_used = 0
		GROUP BY e.id;
        
	DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    OPEN CurDate;
	REPEAT
		FETCH CurDate INTO EventId;
		IF NOT Done THEN
        
			SET EventDetailCount = 0;
    
			SELECT COUNT(*) 
            INTO EventDetailCount
            FROM event_details
            WHERE event_id = EventId
            AND start_time >= DATE_SUB(NOW(), INTERVAL 1 DAY);
            
            IF EventDetailCountLimit > EventDetailCount THEN
				INSERT INTO event_details(event_id, action_id, order_type_id, vendor_id, game_id, game_join_event_used, rate, game_type_id, point
						, start_time, end_time, status, admin)
				SELECT event_id, action_id, order_type_id, vendor_id, game_id, game_join_event_used, rate, game_type_id, point
						, DATE_ADD(start_time, interval 1 day), DATE_ADD(end_time, INTERVAL 1 DAY), 1, admin
				FROM event_details
				WHERE event_id = EventId
				ORDER BY id DESC
				LIMIT 1;
				
				SELECT LAST_INSERT_ID() INTO EventDetailId;
		
				INSERT INTO w_jackpots(event_id, event_detail_id, no, draw_time, status, admin)
				SELECT EventId, EventDetailId, (no + 1), DATE_ADD(draw_time, INTERVAL 1 DAY), 0, admin
				FROM w_jackpots
				WHERE event_id = EventId
				ORDER BY id DESC
				LIMIT 1;
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
