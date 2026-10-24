<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpSetLotteryTicketNumber extends Migration
{

    protected $procedure_name = 'SP_set_lottery_ticket_number';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(IN p_lucky_draw_id INT, IN p_draw_number VARCHAR(8))
BEGIN

	# 設定當期抽獎券開獎號碼
    # 2020/11/02 Mars 		新增

    DECLARE LuckyDrawTime DateTime;
    DECLARE LuckyDrawStatus INT;
    DECLARE ErrorCode INT DEFAULT 0;
    DECLARE ErrorMessage VARCHAR(50) DEFAULT '';
    DECLARE TgMessage VARCHAR(500) DEFAULT '';
    
    # 檢查獎號
    IF (SELECT p_draw_number REGEXP '^[0-9]{6}-[0-9]{1}') THEN
		# 取得最後一筆已超過開獎時間且未開獎的紀錄
		SELECT draw_time, status INTO LuckyDrawTime, LuckyDrawStatus
		FROM lucky_draws 
		WHERE id = p_lucky_draw_id
        AND draw_number IS NULL 
		ORDER BY no LIMIT 1;
        
        IF LuckyDrawTime IS NULL THEN
			SET ErrorCode = 1001;
			SET ErrorMessage = 'NOT FOUND';  # 查無當期獎號
        ELSEIF LuckyDrawStatus != 0 THEN
			SET ErrorCode = 1002;
			SET ErrorMessage = 'STATUS IS NOT ZERO'; # 狀態不為0
        #ELSEIF LuckyDrawTime > NOW() THEN
		#	SET ErrorCode = 1003;
		#	SET ErrorMessage = CONCAT('開獎時間', DATE_ADD(LuckyDrawTime, INTERVAL 8 HOUR) ,' 未到');
		ELSE 
			# 寫入當期獎號
			UPDATE lucky_draws SET draw_number = p_draw_number WHERE id = p_lucky_draw_id;
            
            CALL SP_payout_lottery_ticket_reward(p_lucky_draw_id, TgMessage);
		END IF;
    ELSE
		SET ErrorCode = 1004;
		SET ErrorMessage = CONCAT('獎號格式錯誤', p_draw_number, ', 需為123456-7');
    END IF;
    
    SELECT ErrorCode, ErrorMessage, TgMessage;
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
