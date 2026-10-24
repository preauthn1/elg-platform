CREATE PROCEDURE `SP_summary_bet_record_agent_recalculate`(
    IN p_StartTime DATETIME,
    IN p_EndTime DATETIME,
    IN p_AgentId INT(11))
BEGIN
    # 將summary_bet_record_agents的代理佔成重算
    #重算呼叫方式: CALL SP_summary_bet_record_agent_recalculate('2021-01-01', '2021-01-31', 33893);

    # 2023/03/03 Neil 			Add

    DECLARE Done INT DEFAULT 0;
    DECLARE AgentProportion VARCHAR(20);
    DECLARE SummaryId, VendorId, AgentId INT DEFAULT 0;
    DECLARE TotalBet, TotalWinLose, TotalJackpot, TotalShareAmount, TotalCommission, TotalVJackpot, TotalCost, TotalScore DECIMAL(18, 4) DEFAULT '0.0000';

    # 取出需要改的紀錄
    DECLARE cur CURSOR FOR
        SELECT id, vendor_id, total_bet, total_win_lose, total_jackpot, total_share_amount, total_commission, total_v_jackpot, total_cost, total_score
        FROM summary_agent_achievements
        WHERE summary_time >= p_StartTime AND summary_time < p_EndTime AND agent_id = p_AgentId;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_summary_bet_record_agent_recalculate(',
                    IFNULL(p_StartTime, 'NULL'), ',',
                    IFNULL(p_EndTime, 'NULL'), ',',
                    IFNULL(p_AgentId, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    OPEN cur;
    #取得更新後的佔成
    SELECT proportion INTO AgentProportion
    FROM agents
    WHERE id = p_AgentId;

    REPEAT
        FETCH cur
            INTO SummaryId, VendorId, TotalBet, TotalWinLose, TotalJackpot, TotalShareAmount, TotalCommission, TotalVJackpot, TotalCost, TotalScore;
        IF NOT Done THEN
            IF VendorId = 4 THEN
                UPDATE summary_agent_achievements
                SET proportion = TotalBet * 0.19
                WHERE id = SummaryId;
            ELSE
                UPDATE summary_agent_achievements
                SET proportion = (TotalWinLose + TotalJackpot + TotalShareAmount + TotalCommission + TotalVJackpot + TotalCost ) * AgentProportion * -1
                WHERE id = SummaryId;
            END IF;
        END IF;
    UNTIL Done END REPEAT;
    CLOSE cur;
END
