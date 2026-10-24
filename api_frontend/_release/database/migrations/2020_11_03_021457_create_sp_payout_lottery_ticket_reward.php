<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpPayoutLotteryTicketReward extends Migration
{

    protected $procedure_name = 'SP_payout_lottery_ticket_reward';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(IN p_lucky_draws_id INT, OUT p_tg_message VARCHAR(500))
BEGIN

	# 派發當期中獎抽獎券
    # 2020/11/02 Mars 		新增

	DECLARE Done INT DEFAULT 0;
	DECLARE RowCount INT DEFAULT 0;
    DECLARE LuckyDrawTime DateTime;
    DECLARE LuckyDrawNumber VARCHAR(8);
    DECLARE StartTime, EndTime DateTime DEFAULT NOW();
    DECLARE ReturnValue INT DEFAULT 0;
    DECLARE ErrorMessage VARCHAR(50) DEFAULT '';
    
	DECLARE RewardId, RewardLevel, PrizeId, PrizeContentID INT DEFAULT 0;
	DECLARE Bonus DECIMAL(16, 2) DEFAULT 0;
	DECLARE L_no, R_no VARCHAR(8);
	DECLARE DrawName VARCHAR(50) DEFAULT '';
    
	# 取出抽獎獎號
    DECLARE cur CURSOR FOR 
    SELECT r.id
			, r.level
			, TRUNCATE(r.bonus, 2) AS bonus
			, CASE WHEN level = 1 THEN l.draw_number 
					WHEN level = 2 THEN CONCAT(SUBSTRING_INDEX(l.draw_number, '-', 1), '%') 
					ELSE CONCAT(LEFT(SUBSTRING_INDEX(l.draw_number, '-', 1), (8 - r.level)), '%') END AS l_no
			, CASE WHEN level > 2 THEN CONCAT('%', RIGHT(SUBSTRING_INDEX(l.draw_number, '-', 1), (8 - r.level)), '-%') ELSE '' END AS r_no
			, p.id AS prize_id
			, r.name AS draw_name
			#, r.type AS reward_item_type
			, pc.id AS prize_content_id
	FROM lucky_draws AS l
	JOIN prizes as p ON p.event_id = l.event_id
	JOIN prize_contents AS pc ON pc.prize_id = p.id AND pc.status = 1
	JOIN reward_items AS r ON pc.point = r.bonus AND r.status = 1
	WHERE l.id = p_lucky_draws_id AND l.status = 0 AND l.draw_number IS NOT NULL
	ORDER BY r.level;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
            
	OPEN cur;
    
	SELECT DATE_ADD(draw_time, INTERVAL 8 HOUR)
			, draw_number 
	INTO LuckyDrawTime, LuckyDrawNumber
    FROM lucky_draws
    WHERE id = p_lucky_draws_id;
            
	SET p_tg_message = CONCAT('[抽獎券開奬日期 ', DATE(LuckyDrawTime), ']\n');
    SET p_tg_message = CONCAT(p_tg_message, '-----------------------\n');
    SET p_tg_message = CONCAT(p_tg_message, '開奬號碼:', LuckyDrawNumber, '\n');

    REPEAT
		FETCH cur INTO RewardId, RewardLevel, Bonus, L_no, R_no, PrizeId, DrawName, PrizeContentID;
		IF NOT Done THEN
            # SELECT RewardLevel, Bonus, L_no, R_no, PrizeId, DrawName, PrizeContentID;
            UPDATE lucky_draws SET status = 2 WHERE id = p_lucky_draws_id;
            
            # 中獎名單
            UPDATE lucky_draw_tickets SET reward_item_id = RewardId, point = Bonus, type = 1
            WHERE lucky_draw_id = p_lucky_draws_id
            AND reward_item_id = 0
            AND (ticket_number LIKE L_no OR ticket_number LIKE R_no);
            
            # update 影響筆數
			SELECT row_count() INTO RowCount; 
            
            # 更新獎項內容派發數量
            UPDATE prize_contents SET sent = sent + RowCount WHERE id = PrizeContentID;
            
            INSERT INTO prize_members(member_id, prize_id, content_id, lottery_number, status, admin)
            SELECT ldt.member_id, PrizeId, PrizeContentID, ldt.id, 0, 0
            FROM lucky_draw_tickets as ldt
            WHERE ldt.lucky_draw_id = p_lucky_draws_id
            AND ldt.reward_item_id = RewardId
            # 排除重複派發
            #AND NOT EXISTS (SELECT * FROM prize_members AS pm WHERE pm.prize_id = PrizeId AND pm.lottery_number = ldt.id)
            ;
            
            # update 影響筆數
			SELECT row_count() INTO RowCount; 
            
            SET p_tg_message = CONCAT(p_tg_message, DrawName, '|', Bonus, '|', RowCount, '\n');
		END IF;
	UNTIL Done END REPEAT;
    
    CLOSE cur;
    
    # 將該期修改為已派發
    UPDATE lucky_draws SET status = 1 WHERE id = p_lucky_draws_id;
    
    SET EndTime = NOW();
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
