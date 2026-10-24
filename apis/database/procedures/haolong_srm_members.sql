CREATE PROCEDURE `haolong_srm_members`()
BEGIN

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 
			@p2 = MESSAGE_TEXT;
            
        # 發通知
        CALL COM_Send_Error_Message(
			CONCAT(
				'[系統通知] SP_calc_three_hierarchy_bonus(',
                IFNULL(p_summary_time, 'NULL'), ',',
				') 異常 ', @p2), 
			'db'
		);
    END;
    
	select 
		(SELECT username FROM agents WHERE id IN(SELECT parent FROM members where id=n.member_id)) agent_username,
		(SELECT username FROM members where id=n.member_id) member_username,
		username as game_username
	from haolong_accounts n 
    where n.member_id in (
		SELECT id 
        FROM members m 
        where m.parent in (
			SELECT id FROM agents where username like 'srm%'
		)
	);

END