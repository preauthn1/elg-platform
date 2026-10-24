<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpCalcUnlimitBonusMerge extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure_name = 'SP_calc_unlimit_bonus_merge';
        $procedure = "CREATE PROCEDURE `".$procedure_name."`(IN SummaryDate datetime)
BEGIN
    DECLARE AdminUser int;
    SET AdminUser = 0;
    SET SummaryDate = IFNULL(SummaryDate,DATE(date_add(NOW(),interval 8 HOUR)) - 1);

	IF EXISTS(SELECT * FROM unlimit_bonusreport WHERE summary_date = SummaryDate) THEN
		#增加unlimit_summary_bonus已經計算的數據
		REPLACE INTO unlimit_summary_bonus(member_id, performance_amount, bonus_amount, withdraw_amount, created_at, updated_at, admin)	
		SELECT br.member_id
		,ifnull(sb.performance_amount,0) + ifnull(br.performance_amount,0) AS performance_amount
		,ifnull(sb.bonus_amount,0) + ifnull(br.bonus_amount,0) AS bonus_amount
        ,ifnull(sb.withdraw_amount,0) AS withdraw_amount
        ,ifnull(sb.created_at,NOW()) AS created_at
		,NOW() AS updated_at
        ,AdminUser
		FROM
		(
			SELECT member_id
			,ifnull(performance_direct + performance_team,0) AS performance_amount
			,ifnull(bonus_direct + bonus_team,0) AS bonus_amount
			FROM unlimit_bonusreport 
			WHERE summary_date = SummaryDate
		) AS br LEFT JOIN unlimit_summary_bonus AS sb ON br.member_id = sb.member_id;
    END IF;
END";
        DB::unprepared('DROP procedure IF EXISTS '.$procedure_name.'');
        DB::unprepared($procedure);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $procedure_name = 'SP_calc_unlimit_bonus_merge';
        DB::unprepared('DROP procedure IF EXISTS '.$procedure_name.'');
    }
}
