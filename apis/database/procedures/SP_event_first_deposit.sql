CREATE PROCEDURE `SP_event_first_deposit`(IN p_order_id INT)
BEGIN
    # 首儲活動
    # 2020/10/14 Mars 		增加首儲活動
    # 2021/09/01 Mars 		增加稽核流水
    # 2022/03/01 Mars 		增加寫入events.id到flow_limits

    DECLARE EventId, MemberId, PlatformAgentId, AgentId, OrderStatus, AgentCashFlowId, OrderID INT;
    DECLARE MemberCreatedAt, StartTime, EndTime DATETIME;
    DECLARE TransactionLogType INT DEFAULT 19;
    DECLARE BeforeBalance, DepositPoint DECIMAL(18, 4);
    DECLARE FirstDeposit VARCHAR(20);
    DECLARE RemarkText VARCHAR(20) DEFAULT '首充活動獎勵';
    DECLARE RewardMultiple DECIMAL(3, 2) DEFAULT 0.20; 		# 獎勵倍數
    DECLARE RewardPoint DECIMAL(18, 4) DEFAULT 0; 		# 獎勵金額
    DECLARE FlowLimitRate DECIMAL(10, 2) DEFAULT 0; 		# 稽核流水倍數
    DECLARE Operator VARCHAR(10) DEFAULT 'system';
    DECLARE OperatorId INT DEFAULT 51;
    DECLARE SettingKey INT DEFAULT 6014;
    DECLARE ReferenceId INT DEFAULT 0;

    # 查首充活動(type_id = 6)
    SELECT e.id, start_time, end_time, e.agent_id
    INTO EventId, StartTime, EndTime, PlatformAgentId
    FROM event_details AS ed
             JOIN events AS e ON e.id = ed.event_id AND e.status = 1
    WHERE ed.action_id = 6
      AND ed.status = 1
      AND start_time <= NOW()
      AND end_time > NOW();

    IF PlatformAgentId > 0 THEN
        # 查訂單資訊
        SELECT m.id AS member_id
             , m.balance
             , m.created_at
             , a.id AS agent_id
             , o.id
             , o.point
             , o.status
             , ifnull(m.first_deposit, '') AS first_deposit
             , o.agent_cash_flow_id
             , IFNULL((acs.value * 1.00), 0) AS flow_limit_rate
        INTO MemberId, BeforeBalance, MemberCreatedAt, AgentId, OrderID
            , DepositPoint, OrderStatus, FirstDeposit, AgentCashFlowId, FlowLimitRate
        FROM orders AS o
                 JOIN members AS m
                      ON m.id = o.member_id
                 JOIN agents AS a
                      ON a.id = m.parent
                          AND a.hierarchy LIKE CONCAT('%,', PlatformAgentId, ',%')
                 LEFT JOIN agent_customized_settings AS acs
                           ON acs.agent_group_id = a.customized_setting_group_id
                               AND setting_key = SettingKey
        WHERE o.id = p_order_id;

        # 判斷訂單是否成立
        IF OrderStatus = 3 AND FirstDeposit = '' THEN
            # 計算獎勵金額
            SET RewardPoint = (DepositPoint * RewardMultiple);

            # 首存欄位寫入當前時間
            UPDATE members set balance = balance + RewardPoint, first_deposit = NOW() WHERE id = MemberId;

            # 寫入transaction_logs
            INSERT INTO transaction_logs(transaction_no, agent_id, member_id, vendor_id, order_id
                                        , type, point, vendor_point, rate
                                        , before_balance, balance, vendor_balance
                                        , status, remark, result, operator, platform, admin)
            VALUES(OrderID, AgentId, MemberId, 0, 0
                  , TransactionLogType, RewardPoint, 0, 1
                  , BeforeBalance, (BeforeBalance + RewardPoint), 0
                  , 1000, RemarkText, 0, Operator, 0, 0);
            # 取得transaction_logs.id
            SELECT LAST_INSERT_ID() INTO ReferenceId;

            # 寫入稽核流水
            IF FlowLimitRate > 0 THEN
                INSERT INTO flow_limits(member_id, type, event_id, reference_id, flow_limit, admin)
                VALUES(MemberId, TransactionLogType, EventId, ReferenceId, (RewardPoint * FlowLimitRate), OperatorId);
            END IF;
        END IF;
    END IF;
END
