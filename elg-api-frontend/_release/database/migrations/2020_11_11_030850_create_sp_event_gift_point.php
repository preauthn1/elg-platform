<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpEventGiftPoint extends Migration
{
    protected $procedure_name = 'SP_event_gift_point';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(IN p_type INT, IN p_member_id INT, IN p_agent_id INT)
BEGIN

	# 註冊送(20)/綁定手機送(21)
    # 2020-11-10 Mars 		Add
    
    DECLARE GiftPoint, BeforeBalance, AfterBalance DECIMAL(10,2);
    DECLARE SettingId INT DEFAULT 0;
    DECLARE TransactionType INT DEFAULT 20;
    DECLARE Remark VARCHAR(50);
    DECLARE FirstBind BOOLEAN DEFAULT FALSE;
    
    IF p_type IS NOT NULL THEN
		IF p_type = 1 THEN
			# 註冊送
			SET SettingId = 65001;
			SET TransactionType = 20;
			SET Remark = '註冊送';
        
		ELSEIF p_type = 2 THEN
			# 綁定手機送
			SET SettingId = 65002;
			SET TransactionType = 21;
			SET Remark = '綁定手機送';
            
            #如果是綁定送, 要確認是否已送過
            SELECT CASE WHEN IFNULL(id, 0) > 0 THEN true ELSE false END INTO FirstBind
            FROM transaction_logs 
            WHERE member_id = p_member_id 
            AND type = TransactionType;
		END IF;
    
		# 取得註冊送點數
		SELECT IFNULL(value, 0) INTO GiftPoint 
		FROM agent_personal_settings 
		WHERE setting_id = SettingId AND agent_id = p_agent_id;
    
		#
		IF GiftPoint > 0 AND FirstBind = false THEN
			# 檢查玩家是否存在
			IF EXISTS (SELECT * FROM members WHERE id = p_member_id) THEN
				# 取玩家餘額
				SELECT balance INTO BeforeBalance 
				FROM members 
				WHERE id = p_member_id;
                
                SET AfterBalance = (BeforeBalance + GiftPoint);
            
				# 更新玩家餘額
				UPDATE members 
				SET balance = AfterBalance
				WHERE id = p_member_id;
            
				# 寫入transaction_logs
				INSERT INTO transaction_logs(transaction_no, agent_id, member_id, vendor_id, order_id
					, type, point, tax, vendor_point, rate
					, before_balance, balance, vendor_balance, `status`, remark
					, checksum, result, operator, platform, `admin`)
				VALUES('', p_agent_id, p_member_id, 0, 0
					, TransactionType, GiftPoint, 0, 0, 0
					, BeforeBalance, AfterBalance, 0, 1000, Remark
					, '', '', 0, 0, 0);
            
			END IF;
		END IF;
	END IF;
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
