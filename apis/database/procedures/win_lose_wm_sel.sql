CREATE PROCEDURE `win_lose_wm_sel`()
BEGIN

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] win_lose_wm_sel(',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    select game_identity,g.name,sum(bet_amount),sum(win_amount)
    from wm_bet_records as b
             left join games as g on g.game_type = b.game_identity
    where end_time between '2020-04-01 00:00:00' and '2020-04-01 23:59:59'
    group by game_identity;

END
