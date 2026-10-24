SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @Username = 'T-power';
SET @CheckDate = '2026-07-04';
################################################
SET @StartTime = DATE_SUB(@CheckDate, INTERVAL 8 HOUR);
SET @EndTime = DATE_SUB(DATE_ADD(@CheckDate, INTERVAL 1 DAY), INTERVAL 8 HOUR);
SELECT id, hierarchy_path INTO @AgentId, @HierarchyPath FROM agents WHERE username = @Username;

### DEPOSIT ############
SELECT cf.id as cf_id
    , cf.name as cf_name
    , acf.id as acf_id
    , acf.name as acf_name
	, ot.id AS otid -- :fire: 由 dim 預先展開，沒訂單也有 otid
    , IFNULL(otn.name, ot.name) AS order_type_name
    , @CheckDate AS summary_time -- :fire: 使用連續日期表的日期
    , IFNULL(SUM(z.amount), 0) AS amount -- :fire: 沒資料補 0
FROM cash_flows AS cf
JOIN agent_cash_flows AS acf
	ON acf.cash_flow_id = cf.id
    AND acf.agent_group_id in (
		select cash_flow_group_id from agents WHERE hierarchy_path LIKE CONCAT(@HierarchyPath, '%')
    )
    AND acf.backend_display = 1
JOIN ( -- :fire: acf 可用的頂層 order_type 維度；pivot 多指向子層、且同頂層可有多筆，必須先 DISTINCT 收斂避免漏列與 fan-out 重複計算
	SELECT DISTINCT acfot.agent_cash_flow_id AS acfid
		, CASE WHEN ot.parent = 0 THEN ot.id ELSE ot.parent END AS otid
	FROM agent_cash_flow_order_types AS acfot
	JOIN order_types AS ot ON ot.id = acfot.order_type_id
) AS dim ON dim.acfid = acf.id
JOIN order_types AS ot ON ot.id = dim.otid
LEFT JOIN order_type_names AS otn ON otn.type_id = ot.id AND otn.language_id = 2
LEFT JOIN (
	SELECT o.agent_cash_flow_id AS acfid
		, CASE WHEN ot.parent = 0 THEN o.order_type_id ELSE ot.parent END AS otid
		, SUM(o.amount) AS amount
	FROM orders o
    STRAIGHT_JOIN agent_cash_flows AS acf ON acf.id = o.agent_cash_flow_id
	JOIN cash_flows as cf
		ON cf.id = acf.cash_flow_id
        AND cf.type IN (1, 2)
        AND cf.id NOT IN (5)
	JOIN order_types as ot ON ot.id = o.order_type_id
    LEFT JOIN members AS m ON m.id = o.member_id
	LEFT JOIN agents AS a ON a.id = m.parent AND a.hierarchy_path LIKE CONCAT(@HierarchyPath, '%')
    LEFT JOIN agents AS aa ON aa.id = o.agent_id AND aa.hierarchy_path LIKE CONCAT(@HierarchyPath, '%')
    WHERE (
		(cf.id NOT IN (3, 26, 67, 81, 97, 107) AND o.created_at >= @StartTime AND o.created_at < @EndTime)
		OR
		(cf.id IN (3, 26, 67, 81, 97, 107) AND o.pay_time >= @StartTime AND o.pay_time < @EndTime)
	)
	AND o.status = 3
    AND (a.id IS NOT NULL OR aa.id IS NOT NULL)
    GROUP BY o.agent_cash_flow_id, otid
) AS z ON z.acfid = acf.id AND z.otid = ot.id
WHERE cf.type = 1 AND cf.status = 1
GROUP BY cf.id, acf.name, ot.id
ORDER BY cf.id, acf.name, ot.id;
