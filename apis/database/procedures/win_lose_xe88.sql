CREATE PROCEDURE `win_lose_xe88`()
BEGIN

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] win_lose_xe88(',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    select DATE_FORMAT(DATE_ADD(utc_log_time,INTERVAL 8 HOUR),'%Y-%m-%d'),
           sum(bet),
           sum(win-bet)
    from xe88_bet_records
    group by DATE_FORMAT(DATE_ADD(utc_log_time,INTERVAL 8 HOUR),'%Y-%m-%d');

END
