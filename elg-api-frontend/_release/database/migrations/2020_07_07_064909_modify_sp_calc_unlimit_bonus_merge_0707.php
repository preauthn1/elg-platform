<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifySpCalcUnlimitBonusMerge0707 extends Migration
{
    protected $procedure_name = 'SP_calc_unlimit_bonus_merge';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `" . $this->procedure_name . "`(IN SummaryDate datetime)
BEGIN
	# 2020-07-07 mars 	unlimit_bonusreport增加group by 
    DECLARE AdminUser int;
    SET AdminUser = 0;
    SET SummaryDate = IFNULL(SummaryDate, DATE(date_add(NOW(), interval -16 HOUR)));

	IF EXISTS(SELECT * FROM unlimit_bonusreport WHERE summary_date = SummaryDate) THEN
		#增加unlimit_summary_bonus已經計算的數據
		INSERT INTO unlimit_summary_bonus(member_id, performance_amount, bonus_amount, withdraw_amount, created_at, updated_at, admin)	
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
            ,SUM(performance_direct + performance_team) AS performance_amount
            ,SUM(bonus_direct + bonus_team) AS bonus_amount
			FROM unlimit_bonusreport 
			WHERE summary_date = SummaryDate
            GROUP BY member_id
		) AS br 
        LEFT JOIN unlimit_summary_bonus AS sb ON br.member_id = sb.member_id
        ON DUPLICATE KEY UPDATE 
			performance_amount = ifnull(sb.performance_amount,0) + ifnull(br.performance_amount,0),
            bonus_amount = ifnull(sb.bonus_amount,0) + ifnull(br.bonus_amount,0),
            withdraw_amount = ifnull(sb.withdraw_amount,0);
    END IF;
END";
        DB::unprepared('DROP procedure IF EXISTS ' . $this->procedure_name . '');
        DB::unprepared($procedure);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::unprepared('DROP procedure IF EXISTS ' . $this->procedure_name . '');
    }
}
