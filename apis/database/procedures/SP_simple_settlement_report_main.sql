CREATE PROCEDURE `SP_simple_settlement_report_main`(IN p_agent_id INT, IN p_start_date DATETIME, IN p_end_date DATETIME, OUT TotalReg INT, OUT PeriodReg INT,
                                                    OUT Deposit DECIMAL(18,4), OUT Withdrawal DECIMAL(18,4), OUT Bonus DECIMAL(18,4), OUT Bet DECIMAL(18,4), OUT WinLose DECIMAL(18,4), OUT Jackpot DECIMAL(18,4))
BEGIN
    # 注冊總數
    select count(0) INTO TotalReg from members as m
                                           join agents as a on m.parent = a.id
                                           join (
        select id, username, hierarchy_path from agents where id = p_agent_id
    ) as ma on a.hierarchy_path like concat(ma.hierarchy_path, '%')
    where m.status = 1;
    # 期間注冊人數
    select count(0) INTO PeriodReg from members as m
                                            join agents as a on m.parent = a.id
                                            join (
        select id, username, hierarchy_path from agents where id = p_agent_id
    ) as ma on a.hierarchy_path like concat(ma.hierarchy_path, '%')
    where m.status = 1 and m.created_at >= p_start_date and m.created_at < p_end_date;

    # 期間開分金額
    select sum(point) INTO Deposit from transaction_logs as l
                                            join members as m on l.member_id = m.id
                                            join agents as a on m.parent = a.id
                                            join (
        select id, username, hierarchy_path from agents where id = p_agent_id
    ) as ma on a.hierarchy_path like concat(ma.hierarchy_path, '%')
    where l.status = 1000 and l.type in (3, 4) and l.created_at >= p_start_date and l.created_at < p_end_date;

    # 期間洗分金額
    select sum(point) INTO Withdrawal from transaction_logs as l
                                               join members as m on l.member_id = m.id
                                               join agents as a on m.parent = a.id
                                               join (
        select id, username, hierarchy_path from agents where id = p_agent_id
    ) as ma on a.hierarchy_path like concat(ma.hierarchy_path, '%')
    where l.status = 1000 and l.type = 5 and l.created_at >= p_start_date and l.created_at < p_end_date;

    CALL SP_simple_settlement_report_pnl(p_agent_id, p_start_date, p_end_date, Bonus, Bet, WinLose, Jackpot);

    SET TotalReg = IFNULL(TotalReg, 0);
    SET PeriodReg = IFNULL(PeriodReg, 0);
    SET Deposit = IFNULL(Deposit, 0);
    SET Withdrawal = IFNULL(Withdrawal, 0);
    SET Bonus = IFNULL(Bonus, 0);
    SET Bet = IFNULL(Bet, 0);
    SET WinLose = IFNULL(WinLose, 0);
    SET Jackpot = IFNULL(Jackpot, 0);

    #SELECT TotalReg, PeriodReg, Deposit, Withdrawal, Bonus, Bet, WinLose, Jackpot;
END
