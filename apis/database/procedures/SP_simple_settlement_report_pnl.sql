CREATE PROCEDURE `SP_simple_settlement_report_pnl`(
    IN p_agent_id INT,
    IN p_start_date DATETIME,
    IN p_end_date DATETIME,
    OUT Bonus DECIMAL(18,4),
    OUT Bet DECIMAL(18,4),
    OUT WinLose DECIMAL(18,4),
    OUT Jackpot DECIMAL(18,4)
)
BEGIN
    Declare UnlimitBonus, UnlimitBonusAgent DECIMAL(18,4);

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_simple_settlement_report_pnl(',
                    IFNULL(p_agent_id, 'NULL'), ',',
                    IFNULL(p_start_date, 'NULL'), ',',
                    IFNULL(p_end_date, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    #select p_start_date, p_end_date;
    # 期間全民代獎勵
    SELECT sum(TRUNCATE(ubr.bonus_direct, 2)) + sum(TRUNCATE(ubr.bonus_team, 2)) INTO UnlimitBonus
    FROM unlimit_bonusreport as ubr
             join members as m on ubr.member_id = m.id
             join agents as a on m.parent = a.id
             join (
        select id, username, hierarchy_path from agents where id = p_agent_id
    ) as ma on a.hierarchy_path like concat(ma.hierarchy_path, '%')
    where ubr.created_at >= p_start_date and ubr.created_at < p_end_date;
    SELECT sum(TRUNCATE(ubra.bonus_direct, 2)) + sum(TRUNCATE(ubra.bonus_team, 2)) INTO UnlimitBonusAgent
    FROM unlimit_bonusreport_agents as ubra
             join agents as a on ubra.agent_id = a.id
             join (
        select id, username, hierarchy_path from agents where id = p_agent_id
    ) as ma on a.hierarchy_path like concat(ma.hierarchy_path, '%')
    where ubra.created_at >= p_start_date and ubra.created_at < p_end_date;
    # 期間獎勵金額
    select sum(point) INTO Bonus from transaction_logs as l
                                          join members as m on l.member_id = m.id
                                          join agents as a on m.parent = a.id
                                          join (
        select id, username, hierarchy_path from agents where id = p_agent_id
    ) as ma on a.hierarchy_path like concat(ma.hierarchy_path, '%')
    where l.status = 1000 and (l.type in (13, 18, 19, 20, 21, 24, 25, 26, 30, 31) or l.type between 101 and 500 )
      and l.created_at >= p_start_date and l.created_at < p_end_date;
    select UnlimitBonus, UnlimitBonusAgent, Bonus;
    SET Bonus = ifnull(Bonus, 0) + ifnull(UnlimitBonus, 0) + ifnull(UnlimitBonusAgent, 0);
    # 押注與輸贏
    select sum(b.bet), sum(b.win_lose), sum(b.jackpot) INTO Bet, WinLose, Jackpot from summary_bet_records as b
                                                                                           join members as m on b.member_id = m.id
                                                                                           join agents as a on m.parent = a.id
                                                                                           join (
        select id, username, hierarchy_path from agents where id = p_agent_id
    ) as ma on a.hierarchy_path like concat(ma.hierarchy_path, '%')
    where b.summary_time >= p_start_date and b.summary_time < p_end_date;

    #select Bet, WinLose, Bonus;
END
