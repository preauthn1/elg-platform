CREATE PROCEDURE `SP_summary_bet_record_team`(
    IN p_summary_time DATETIME
)
BEGIN
    # call SP_summary_bet_record_team('2021-12-18 16:00:00');
    # 2020-07-07 mars 		增加無限代關閉
    # 2020-07-20 mars 		918(3), mega888(10), pussy888(23)這三家遊戲商每小時紀錄都刪掉重建, 增加一個SummaryTime, 數值為DATE(StartTime)
    # 2021-09-03 mars 		SummaryTime的時間修改為StartTime +8 hour後再取date()
    # 2023-03-21 neil		新增禁止計算
    # 2023-05-09 neil		agent_mode改成吃群組
    # 2025-04-15 Mars 		優化group by, keyword加上``

    DECLARE MaxLevel, AdminUser, AgentModeSettingId INT DEFAULT 0;
    DECLARE StartTime, EndTime, SummaryTime, SettlementDate, ExecStart, ExecEnd DATETIME;
    DECLARE Params VARCHAR(200);

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_summary_bet_record_team(',
                    IFNULL(p_summary_time, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SET ExecStart = NOW();
    SET Params = CONCAT(p_summary_time);
    SET AgentModeSettingId = 7001;
    #判斷是否有傳入參數，沒有則抓取前一小時時間區間進行統計
    IF p_summary_time IS NULL THEN
        SET EndTime = DATE_ADD(CURDATE(), INTERVAL HOUR(NOW()) HOUR);
        SET StartTime = DATE_ADD(EndTime, INTERVAL -1 HOUR);
    ELSE
        SET StartTime = p_summary_time;
        SET EndTime = DATE_ADD(StartTime, INTERVAL 1 HOUR);
    END IF;
    SET SummaryTime = DATE(DATE_ADD(StartTime, INTERVAL 8 HOUR));
    SET SettlementDate = DATE(DATE_ADD(StartTime, INTERVAL 8 HOUR));
    SET MaxLevel = (SELECT MAX(agent_level) FROM member_agent);

    #判斷是否已經統計過，有則先進行刪除
    IF EXISTS(SELECT * FROM summary_bet_records_team WHERE summary_time = StartTime AND vendor_id NOT IN (3, 10, 23)) THEN
        DELETE FROM summary_bet_records_team
        WHERE summary_time = StartTime
          AND vendor_id NOT IN (3, 10, 23);
    END IF;
    IF EXISTS(SELECT * FROM summary_bet_records_team WHERE summary_time = SummaryTime AND vendor_id IN (3, 10, 23)) THEN
        DELETE FROM summary_bet_records_team
        WHERE summary_time = SummaryTime
          AND vendor_id IN (3, 10, 23);
    END IF;

    WHILE MaxLevel > 0
        DO
            INSERT INTO summary_bet_records_team(
                summary_time, member_id, vendor_id, game_id,
                bet, bet_direct, bet_team,
                win_lose, win_lose_direct, win_lose_team, `admin`
            )
            SELECT StartTime
                 , D.member_id
                 , D.vendor_id
                 , D.game_id
                 , SUM(D.bet) AS bet
                 , SUM(D.bet_direct) AS bet_directbet
                 , SUM(D.bet_team) AS bet_team
                 , SUM(D.win_lose) AS win_lose
                 , SUM(D.win_lose_direct) AS win_lose_direct
                 , SUM(D.win_lose_team) AS win_lose_team
                 , AdminUser
            FROM (
                     SELECT MA.member_id
                          , B.vendor_id
                          , B.game_id
                          , CASE MUC.`status` WHEN 1 THEN 0 ELSE SUM(B.bet) END AS bet
                          , SUM(B.win_lose) AS win_lose
                          , 0 AS bet_direct
                          , 0 AS win_lose_direct
                          , 0 AS bet_team
                          , 0 AS win_lose_team
                     FROM member_agent AS MA
                              JOIN agents AS A
                                   ON A.id = MA.parent_id #AND A.agent_mode = 1 改成下面那段吃群組
                              JOIN agent_customized_settings AS ACS
                                   ON ACS.agent_group_id = A.customized_setting_group_id
                                       AND setting_key = AgentModeSettingId
                                       AND ACS.`value` = 1
                              JOIN summary_bet_records AS B
                                   ON B.member_id = MA.member_id
                              LEFT JOIN member_unlimit_cancels AS MUC
                                        ON MUC.member_id = MA.member_id
                                            AND `date` = SettlementDate
                     WHERE MA.agent_level = MaxLevel
                       AND B.summary_time >= StartTime AND B.summary_time < EndTime
                       AND B.vendor_id NOT IN (3, 10, 23)
                     GROUP BY MA.member_id,B.vendor_id,B.game_id
                     UNION ALL
                     SELECT MA.topper_member_id AS member_id
                          , B.vendor_id
                          , B.game_id
                          , 0 AS bet
                          , 0 AS win
                          , SUM(B.bet) AS bet_direct
                          , SUM(B.win_lose) AS win_lose_direct
                          , SUM(B.bet_direct + B.bet_team) AS bet_team
                          , SUM(B.win_lose_direct + B.win_lose_team) AS win_lose_team
                     FROM member_agent AS MA
                              JOIN agents AS A
                                   ON A.id = MA.parent_id #AND A.agent_mode = 1 改成下面那段吃群組
                              JOIN agent_customized_settings AS ACS
                                   ON ACS.agent_group_id = A.customized_setting_group_id
                                       AND setting_key = AgentModeSettingId
                                       AND ACS.`value` = 1
                              JOIN summary_bet_records_team AS B
                                   ON B.member_id = MA.member_id
                     WHERE MA.agent_level = (MaxLevel + 1)
                       AND B.summary_time >= StartTime
                       AND B.summary_time < EndTime
                       AND B.vendor_id NOT IN (3, 10, 23)
                     GROUP BY MA.member_id, B.vendor_id, B.game_id
                 ) AS D
            GROUP BY D.member_id, D.vendor_id, D.game_id;

            ####################################################################
            # 918, MEGA888, PUSSY888
            INSERT INTO summary_bet_records_team(
                summary_time, member_id, vendor_id, game_id,
                bet, bet_direct, bet_team,
                win_lose, win_lose_direct, win_lose_team, `admin`
            )
            SELECT SummaryTime, D.member_id, D.vendor_id, D.game_id
                 , SUM(D.bet) AS bet
                 , SUM(D.bet_direct) AS bet_directbet
                 , SUM(D.bet_team) AS bet_team
                 , SUM(D.win_lose) AS win_lose
                 , SUM(D.win_lose_direct) AS win_lose_direct
                 , SUM(D.win_lose_team) AS win_lose_team
                 , AdminUser
            FROM (
                     SELECT MA.member_id
                          , B.vendor_id
                          , B.game_id
                          , CASE MUC.`status` WHEN 1 THEN 0 ELSE sum(B.bet) END AS bet
                          , SUM(B.win_lose) AS win_lose
                          , 0 AS bet_direct
                          , 0 AS win_lose_direct
                          , 0 AS bet_team
                          , 0 AS win_lose_team
                     FROM member_agent AS MA
                              JOIN agents AS A
                                   ON A.id = MA.parent_id #AND A.agent_mode = 1 改成下面那段吃群組
                              JOIN agent_customized_settings AS ACS
                                   ON ACS.agent_group_id = A.customized_setting_group_id
                                       AND setting_key = AgentModeSettingId
                                       AND ACS.`value` = 1
                              JOIN summary_bet_records AS B
                                   ON B.member_id = MA.member_id
                              LEFT JOIN member_unlimit_cancels AS MUC
                                        ON MUC.member_id = MA.member_id
                                            AND `date` = SettlementDate
                     WHERE MA.agent_level = MaxLevel
                       AND B.summary_time = SummaryTime
                       AND B.vendor_id IN (3, 10, 23)
                     GROUP BY MA.member_id, B.vendor_id, B.game_id
                     UNION ALL
                     SELECT MA.topper_member_id AS member_id
                          , B.vendor_id
                          , B.game_id
                          , 0 AS bet
                          , 0 AS win
                          , SUM(B.bet) AS bet_direct
                          , SUM(B.win_lose) AS win_lose_direct
                          , SUM(B.bet_direct + B.bet_team) AS bet_team
                          , SUM(B.win_lose_direct + B.win_lose_team) AS win_lose_team
                     FROM member_agent AS MA
                              JOIN agents AS A
                                   ON A.id = MA.parent_id #AND A.agent_mode = 1 改成下面那段吃群組
                              JOIN agent_customized_settings AS ACS
                                   ON ACS.agent_group_id = A.customized_setting_group_id
                                       AND setting_key = AgentModeSettingId
                                       AND ACS.`value` = 1
                              JOIN summary_bet_records_team AS B
                                   ON B.member_id = MA.member_id
                     WHERE MA.agent_level = (MaxLevel + 1)
                       AND B.vendor_id IN (3, 10, 23)
                       AND B.summary_time = SummaryTime
                     GROUP BY MA.member_id, B.vendor_id, B.game_id
                 ) AS D
            GROUP BY D.member_id, D.vendor_id, D.game_id;

            SET MaxLevel = MaxLevel - 1;
        END WHILE;

    SET ExecEnd = NOW();

    CALL COM_Update_Control_Table(10006, 'summary_bet_records_team', 0, StartTime, EndTime, ExecStart, ExecEnd, Params);

END
