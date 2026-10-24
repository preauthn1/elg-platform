CREATE PROCEDURE `win_lose_mega888`()
BEGIN

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] win_lose_mega888(',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SELECT DATE_FORMAT(DATE_ADD(m.date,INTERVAL 0 HOUR),'%Y-%m-%d'),sum(bet),
           sum(win),
           sum(win-bet)
    FROM mega888_daily_wins as m
    where DATE_FORMAT(DATE_ADD(m.created_at,INTERVAL 0 HOUR),'%Y-%m-%d')='2019-11-05'
    group by DATE_FORMAT(DATE_ADD(m.date,INTERVAL 0 HOUR),'%Y-%m-%d');

END
