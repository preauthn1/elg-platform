CREATE PROCEDURE `ADMIN_get_customized_setting`(
	IN p_AgentGroupId INT,
	IN p_SettingKey INT,
    OUT p_DataType VARCHAR(100),
    OUT p_DataValue VARCHAR(500)
)
BEGIN
	
    DECLARE AgentGroupId INT
		DEFAULT IFNULL(p_AgentGroupId, 0);
    DECLARE SettingKey INT
		DEFAULT IFNULL(p_SettingKey, 0);
    
	### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 
			@p2 = MESSAGE_TEXT;
            
        # 發通知
        CALL COM_Send_Error_Message(
			CONCAT(
				'[系統通知] ADMIN_get_customized_setting(',
                IFNULL(p_AgentGroupId, 'NULL'), ',',
                IFNULL(p_SettingKey, 'NULL'),
				') 異常 ', @p2), 
			'db'
		);
    END;
    
    SELECT asd.data_type, IFNULL(acs.value, asd.default_value)
    INTO p_DataType, p_DataValue
	FROM agent_setting_defines AS asd
	LEFT JOIN agent_customized_settings AS acs
		ON acs.setting_key = asd.setting_id
		AND acs.agent_group_id = AgentGroupId
	WHERE asd.setting_id = SettingKey;

END