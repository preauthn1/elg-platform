CREATE PROCEDURE `SP_calc_vip_flow_limit`(
    IN p_MemberId INT,
    IN p_VipBet DECIMAL(18,4)
)
BEGIN
    # 稽核流水計算
    #
    # 2021-01-28 Neil 			Add
    # 2025-05-13 Neil 			修改為流水打多少則VIP儲值金額加多少
    # 2025-06-11 Mars 			修正sp 通知及rollback刪除

    DECLARE p_ExecStart DATETIME DEFAULT NOW();
    DECLARE p_ExecEnd DATETIME;

    Block1: BEGIN
        ### SQL異常時發TG通知 ##################
        DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                GET DIAGNOSTICS CONDITION 1
                    @p2 = MESSAGE_TEXT;

                # 發通知
                CALL COM_Send_Error_Message(
                    CONCAT(
                        '[系統通知] SP_calc_vip_flow_limit(',
                        IFNULL(p_MemberId, 'NULL'), ',',
                        IFNULL(p_VipBet, 'NULL'),
                        ') 異常 ', @p2),
                    'db'
                     );
            END;

        Block2: BEGIN
            DECLARE p_Done INT DEFAULT 0;
            DECLARE p_VFID, p_VFMember_id, p_MVAgentId, p_VId, p_VFStatus INT;
            DECLARE p_VFDepositAmount, p_VFlowLimit, p_VReach DECIMAL(18,4);
            DECLARE p_VReackAmount DECIMAL(18,4);
            DECLARE NowLevel, MaxLevel INT DEFAULT 0;

            DECLARE cur CURSOR FOR
                SELECT id, member_id, deposit_amount, flow_limit, reach
                FROM vip_flow_limits
                WHERE member_id = p_MemberId
                  AND status = 0
                ORDER BY id;
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_Done = 1;

            OPEN cur;
            REPEAT
                FETCH cur INTO p_VFID, p_VFMember_id, p_VFDepositAmount, p_VFlowLimit, p_VReach;
                IF p_Done THEN
                    LEAVE Block2;
                END IF;

                # 當有效流水為0 離開
                IF p_VipBet <= 0 THEN
                    LEAVE Block2;
                ELSE
                    SET p_VReackAmount = 0;

                    IF p_VipBet >= (p_VFlowLimit - p_VReach) THEN
                        SELECT status INTO p_VFStatus FROM vip_flow_limits WHERE id = p_VFID;

                        SET p_VReackAmount = (p_VFlowLimit - p_VReach);

                        UPDATE vip_flow_limits
                        SET reach = flow_limit, status = 1
                        WHERE id = p_VFID;

                        SET p_VipBet = (p_VipBet - p_VReackAmount);
                    ELSE
                        SELECT status INTO p_VFStatus FROM vip_flow_limits WHERE id = p_VFID;

                        SET p_VReackAmount = p_VipBet;

                        IF p_VFStatus = 0 THEN
                            UPDATE vip_flow_limits
                            SET reach = (reach + p_VipBet)
                            WHERE id = p_VFID;
                        END IF;
                        SET p_VipBet = 0;
                    END IF;

                    IF p_VReackAmount > 0 THEN
                        CALL SP_calc_member_vip_deposit_amount(p_MemberId, p_VReackAmount, p_VFID);
                    END IF;
                END IF;
            UNTIL p_Done END REPEAT;
            CLOSE cur;
        END Block2;
    END Block1;
END
