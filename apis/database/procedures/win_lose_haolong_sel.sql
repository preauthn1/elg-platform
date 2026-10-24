CREATE PROCEDURE `win_lose_haolong_sel`()
BEGIN

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] win_lose_haolong_sel(',
                    ') 異常 ', @p2),
                'db'
                 );
        END;
    select draw_date,sum(turnover), sum(commission), sum(strike), sum(total)
    from haolong_daily_win_losses
    group by draw_date;

END
