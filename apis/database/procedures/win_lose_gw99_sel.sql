CREATE PROCEDURE `win_lose_gw99_sel`()
BEGIN

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] win_lose_gw99_sel(',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    select DATE_FORMAT(DATE_ADD(m.utc_created_time,INTERVAL 8 HOUR),'%Y-%m-%d')
         , sum(m.betCoin)
         # , sum(m.winAmount-betCoin)
         , sum(m.winAmount) + IFNULL(
        (
            select sum(transAmount)
            from great_wall_transactions
            where DATE_FORMAT(DATE_ADD(utc_created_time,INTERVAL 8 HOUR),'%Y-%m-%d') = DATE_FORMAT(DATE_ADD(m.utc_created_time,INTERVAL 8 HOUR),'%Y-%m-%d')
              and operationMethod = 3
              and result = 1
        ), 0
                              ) - sum(betCoin)
    from great_wall_bet_records as m
    group by DATE_FORMAT(DATE_ADD(m.utc_created_time,INTERVAL 8 HOUR),'%Y-%m-%d');
END
