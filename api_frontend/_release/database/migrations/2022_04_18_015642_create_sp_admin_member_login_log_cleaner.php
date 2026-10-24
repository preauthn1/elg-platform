<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpAdminMemberLoginLogCleaner extends Migration
{
    protected $procedure_name = 'ADMIN_Member_Login_logs_Cleaner';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`()
BEGIN
    # 每日清理三個月前的玩家登入IP紀錄
    #
    # 2022/03/10 Mars 		Add
    
    DECLARE LimitTime DATETIME;
    DECLARE LimitRows INT DEFAULT 10000;
    
    # 取得三個月前的日期
    SET LimitTime = DATE_SUB(DATE_SUB(CURDATE(), INTERVAL 3 MONTH), INTERVAL 8 HOUR);
	
    # 刪除三個月以前的登入紀錄
    DELETE 
    FROM member_login_logs
    WHERE created_at <= LimitTime
    LIMIT LimitRows;

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
