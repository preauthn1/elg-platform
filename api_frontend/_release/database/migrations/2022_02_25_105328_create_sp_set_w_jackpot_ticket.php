<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpSetWJackpotTicket extends Migration
{
    protected $procedure_name = 'SP_set_w_jackpot_ticket';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(
	IN p_event_id INT,
	IN p_w_jackpot_id INT,
    IN p_member_id INT,
    IN p_ticket_number INT
)
BEGIN
	# RD-studio-01 summary_event 呼叫, 派發抽獎券
	# 2022-02-14 	Mars 		W Jackpot抽獎券派發SP 
    
    # 寫入w_jackpot_tickets
    INSERT INTO w_jackpot_tickets(w_jackpot_id, member_id, ticket_number, admin)
	SELECT p_w_jackpot_id, p_member_id, ticket_number, 0
    FROM temp_four_lottery_tickets 
    WHERE event_id = p_event_id
    ORDER BY status, sort
    LIMIT p_ticket_number;
    
    # 更新temp_lottery_tickets狀態, 並重新rand() sort
	UPDATE temp_four_lottery_tickets
    SET sort = FLOOR(RAND() * 10000000), status = (status + 1) 
    WHERE event_id = p_event_id
    ORDER BY status, sort
    LIMIT p_ticket_number;
	
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
