CREATE PROCEDURE `transaction_user`()
BEGIN

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] transaction_user(',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    select created_at
         , (select name from vendors where id=m.vendor_id) vendor_name
         , case type when 1 then '平台轉入遊戲商' when 2 then '遊戲商轉入平台' when 3 then '訂單儲值' when 4 then '開分' when 5 then '洗分' when 6 then '發紅包' when 7 then '領紅包' when 8 then '紅包取消(用戶手動)' when 9 then '紅包退還(系統自動)' when 11 then '拒絕洗分' when 12 then '退還洗分' when 13 then '活動獎勵' end as type
         , m.point
         , m.vendor_point
         , m.balance
         , m.vendor_balance
         , m.status
         , m.remark
    from transaction_logs m
    where m.member_id = 33005
    order by created_at;

END
