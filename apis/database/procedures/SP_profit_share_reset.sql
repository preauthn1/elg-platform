CREATE PROCEDURE `SP_profit_share_reset`(
	IN p_SummaryDate DATE,
    IN p_AgentId INT,
    IN p_PayoutRecordId INT
)
BEGIN

	# 佔成重置
    # 2025/11/19 Mars		Add
	
	DECLARE ExecStart, ExecEnd DATETIME;
    DECLARE SummaryDate DATE 
		DEFAULT DATE_SUB(DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR)), INTERVAL 1 DAY);
	DECLARE StartTime, EndTime DATETIME;
    DECLARE AgentGroupId INT;
    DECLARE AgentId INT DEFAULT p_AgentId;
    DECLARE PayoutRecordId INT DEFAULT p_PayoutRecordId;
    DECLARE HierarchyPath VARCHAR(200);

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 
			@p2 = MESSAGE_TEXT;
            
        # 發通知
        CALL COM_Send_Error_Message(
			CONCAT(
				'[系統通知] SP_profit_share_reset(',
                IFNULL(p_SummaryDate, 'NULL'), ',',
                IFNULL(p_AgentId, 'NULL'), ',',
                IFNULL(p_PayoutRecordId, 'NULL'),
				') 異常 ', @p2), 
			'db'
		);
    END;
    
    SET ExecStart = NOW();
    SET SummaryDate = IFNULL(p_SummaryDate, SummaryDate);
    SET StartTime = DATE_SUB(SummaryDate, INTERVAL 8 HOUR);
    SET EndTime = DATE_SUB(DATE_ADD(StartTime, INTERVAL 1 DAY), INTERVAL 1 SECOND);

    # 取得組別
    SELECT hierarchy_path, customized_setting_group_id
    INTO HierarchyPath, AgentGroupId
	FROM agents 
    WHERE id = AgentId;
    
	UPDATE profit_share_payout_logs 
    SET status = 0
	WHERE id = PayoutRecordId
    AND summary_time = StartTime
    AND agent_group_id = AgentGroupId;

	DELETE FROM profit_share_settings 
    WHERE summary_time = StartTime
    AND agent_group_id = AgentGroupId;
    
	DELETE FROM summary_profit_share_temps 
    WHERE summary_time = StartTime
    AND agent_group_id = AgentGroupId;
    
    
	DELETE FROM summary_profit_share_winlose_temps 
	WHERE summary_time = StartTime
    AND agent_id in (
		SELECT id FROM agents 
        WHERE hierarchy_path LIKE CONCAT(HierarchyPath, '%')
	);
    
	DELETE FROM summary_profit_shares 
    WHERE summary_time = StartTime
    AND agent_id in (
		SELECT id FROM agents 
        WHERE hierarchy_path LIKE CONCAT(HierarchyPath, '%')
	);
    
	SET ExecEnd = NOW();
	CALL COM_Send_Error_Message(
		CONCAT(
			'[系統通知] SP_profit_share_reset(',
			IFNULL(p_SummaryDate, 'NULL'), ',',
			IFNULL(p_AgentId, 'NULL'), ',',
			IFNULL(p_PayoutRecordId, 'NULL'),
			') 計算完畢 ', 
            ExecStart, ' ~ ', ExecEnd), 
		'db'
	);

END