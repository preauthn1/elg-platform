CREATE PROCEDURE `SP_simple_settlement_report_team_commission`(
	IN p_agent_id INT, 
    IN p_start_date DATETIME, 
    IN p_end_date DATETIME,
	OUT Commission DECIMAL(18,4)
)
BEGIN

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 
			@p2 = MESSAGE_TEXT;
            
        # 發通知
        CALL COM_Send_Error_Message(
			CONCAT(
				'[系統通知] SP_simple_settlement_report_team_commission(',
                IFNULL(p_agent_id, 'NULL'), ',',
                IFNULL(p_start_date, 'NULL'), ',',
                IFNULL(p_end_date, 'NULL'), 
				') 異常 ', @p2), 
			'db'
		);
    END;
    
	select sum(final.commission) INTO Commission from (
		select ((t.WinLose + t.Jackpot) * -1 - t.Bonus) * a.percentage as commission from (
			select id, 
				IFNULL(sum(tmp.UnlimitBonus), 0) + IFNULL(sum(tmp.UnlimitBonusAgent), 0) + IFNULL(sum(tmp.Bonus), 0) as Bonus, 
				IFNULL(sum(tmp.Bet), 0) as Bet, 
				IFNULL(sum(tmp.WinLose), 0) as WinLose, 
				IFNULL(sum(tmp.Jackpot), 0) as Jackpot
			from (
				# 期間全民代獎勵
				SELECT a.id, 
					sum(TRUNCATE(ubr.bonus_direct, 2)) + sum(TRUNCATE(ubr.bonus_team, 2)) as UnlimitBonus, 
					0 as UnlimitBonusAgent,
					0 as Bonus,
					0 as Bet,
					0 as WinLose,
					0 as Jackpot
				FROM unlimit_bonusreport as ubr
				join members as m on ubr.member_id = m.id
				join agents as a on m.parent = a.id
				join (
					select id, username, hierarchy_path from agents where parent = p_agent_id
				) as ma on a.hierarchy_path like concat(ma.hierarchy_path, '%')
				where ubr.created_at >= p_start_date and ubr.created_at < p_end_date group by a.id
				union
				SELECT a.id, 
					0 as UnlimitBonusAgent, 
					sum(TRUNCATE(ubra.bonus_direct, 2)) + sum(TRUNCATE(ubra.bonus_team, 2)) as UnlimitBonusAgent,
					0 as Bonus,
					0 as Bet,
					0 as WinLose,
					0 as Jackpot
				FROM unlimit_bonusreport_agents as ubra
				join agents as a on ubra.agent_id = a.id
				join (
					select id, username, hierarchy_path from agents where parent = p_agent_id
				) as ma on a.hierarchy_path like concat(ma.hierarchy_path, '%')
				where ubra.created_at >= p_start_date and ubra.created_at < p_end_date group by a.id
				union
				# 期間獎勵金額
				select a.id, 
					0 as UnlimitBonusAgent, 
					0 as UnlimitBonusAgent, 
					sum(point) as Bonus,
					0 as Bet,
					0 as WinLose,
					0 as Jackpot
				from transaction_logs as l
				join members as m on l.member_id = m.id
				join agents as a on m.parent = a.id
				join (
					select id, username, hierarchy_path from agents where parent = p_agent_id
				) as ma on a.hierarchy_path like concat(ma.hierarchy_path, '%')
				where l.status = 1000 and (l.type in (13, 18, 19, 20, 21, 24, 25, 26, 30, 31) or l.type between 101 and 500 )
				and l.created_at >= p_start_date and l.created_at < p_end_date group by a.id
				union
				# 押注與輸贏
				select a.id, 
					0 as UnlimitBonusAgent, 
					0 as UnlimitBonusAgent, 
					0 as Bonus,
					sum(b.bet) as Bet, 
					sum(b.win_lose) as WinLose, 
					sum(b.jackpot) as Jackpot 
				from summary_bet_records as b
				join members as m on b.member_id = m.id
				join agents as a on m.parent = a.id
				join (
					select id, username, hierarchy_path from agents where parent = p_agent_id
				) as ma on a.hierarchy_path like concat(ma.hierarchy_path, '%')
				where b.summary_time >= p_start_date and b.summary_time < p_end_date
			) as tmp group by id
		) as t
		join agents as a on t.id = a.id
	) as final;
END