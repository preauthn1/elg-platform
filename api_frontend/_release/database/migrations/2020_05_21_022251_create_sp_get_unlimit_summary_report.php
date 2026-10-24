<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpGetUnlimitSummaryReport extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
		$procedureName = 'SP_get_unlimit_summary_report';
		$procedure = "CREATE PROCEDURE `".$procedureName."`(IN p_agent_id VARCHAR(10), IN p_member_id VARCHAR(10)
, IN p_page_index INT, IN p_page_count INT)
BEGIN
	DECLARE p_total_count INT;
    SET p_page_index = IFNULL(p_page_index,1);
    SET p_page_count = IFNULL(p_page_count,20);
    SET p_total_count = (p_page_index - 1) * p_page_count;

	SELECT MA.member_id
	,MA.agent_level
	,SUBSTRING(SUBSTRING_INDEX(MA.agent_relation,',',2),3) AS first_member_id 
	,MA.topper_member_id
	,(
		SELECT COUNT(1)
		FROM member_agent AS T
		WHERE T.agent_relation LIKE CONCAT(MA.agent_relation, '%') AND T.agent_level = MA.agent_level + 1
	) AS member_direct_count
    ,(
		SELECT COUNT(1)
		FROM member_agent AS T
		WHERE T.agent_relation LIKE CONCAT(MA.agent_relation, '%') AND T.agent_level > MA.agent_level + 1
	) AS member_team_count
	,IFNULL(SB.performance_amount,0) AS performance_amount
	,IFNULL(SB.bonus_amount,0) AS bonus_amount
	,IFNULL(SB.withdraw_amount,0) AS withdraw_amount
	,M.created_at AS reg_time
	FROM member_agent AS MA
	JOIN members AS M ON MA.member_id = M.id
    JOIN agents AS A ON A.id = M.parent
	LEFT JOIN unlimit_summary_bonus AS SB ON SB.member_id = MA.member_id
    WHERE A.hierarchy LIKE CONCAT('%,',p_agent_id,',%')
    AND (IFNULL(p_member_id,'') = '' OR MA.member_id = p_member_id OR MA.topper_member_id = p_member_id)
    ORDER BY agent_level,member_id
    LIMIT p_page_count OFFSET p_total_count;
END";
        DB::unprepared('DROP procedure IF EXISTS '.$procedureName.'');
        DB::unprepared($procedure);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
		$procedureName = 'SP_get_unlimit_summary_report';
        DB::unprepared('DROP procedure IF EXISTS '.$procedureName.'');
    }
}
