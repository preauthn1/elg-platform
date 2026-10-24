CREATE PROCEDURE `win_lose_lucky`()
BEGIN

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] win_lose_lucky(',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SELECT DATE_FORMAT(DATE_ADD(bet_time,INTERVAL 8 HOUR),'%Y-%m-%d'),
           SUM(if(freeSpinNum = 0, 1, 0)) totalBetCount,
           SUM(if(freeSpinNum = 0, bet, 0)) totalBet,
           SUM(win) win,
           SUM(win)-SUM(if(freeSpinNum = 0, bet, 0))
    FROM lucky_bet_records
    group by DATE_FORMAT(DATE_ADD(bet_time,INTERVAL 8 HOUR),'%Y-%m-%d');

END
