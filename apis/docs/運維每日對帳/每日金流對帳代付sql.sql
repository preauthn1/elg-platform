SET @Username = 'T-power';
SET @CheckDate = '2026-07-04';
################################################
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @StartTime = DATE_SUB(@CheckDate, INTERVAL 8 HOUR);
SET @EndTime = DATE_SUB(DATE_ADD(@CheckDate, INTERVAL 1 DAY), INTERVAL 8 HOUR);
SELECT id, hierarchy_path INTO @AgentId, @HierarchyPath FROM agents WHERE username = @Username;

### WITHDRAWAL ############
SELECT CASE cf.id
		WHEN 23 THEN 3 # AllPay
		WHEN 43 THEN 26 # SurePay
		WHEN 39 THEN 33 # DGPay MYR
		WHEN 37 THEN 35 # FonPay
		WHEN 40 THEN 38 # DGPay SGD
		WHEN 54 THEN 45 # DGPay(IDR)
		WHEN 68 THEN 67 # FPay
		WHEN 72 THEN 71 # MajesticPay
		WHEN 74 THEN 73 # GMPay
		WHEN 76 THEN 75 # EasyPay
		WHEN 78 THEN 77 # EpicPay
		WHEN 80 THEN 79 # EpicPay
		WHEN 82 THEN 81 # PAYESSENCE
		WHEN 84 THEN 83 # VTPay
		WHEN 88 THEN 87 # QuickPay
		WHEN 90 THEN 89 # TakaPay
		WHEN 92 THEN 91 # KiraPay
		WHEN 94 THEN 93 # TopPay
		WHEN 96 THEN 95 # TrustPay
		WHEN 98 THEN 97 # SurePay
		WHEN 100 THEN 99 # ZPay
		WHEN 102 THEN 101 # PayPro
		WHEN 104 THEN 103 # VaderPay
		WHEN 106 THEN 105 # AKPay
		WHEN 108 THEN 107 # BigPayz
		WHEN 110 THEN 109 # BillionPay
		WHEN 112 THEN 111 # AntzPay
        ELSE cf.id
        END AS cf_id
    , cf.name AS cf_name
    , acf.id AS acf_id
    , acf.name AS acf_name
    , @CheckDate AS summary_time
    , IFNULL(SUM(z.amount), 0) AS amount -- :fire: 沒資料補 0
FROM cash_flows AS cf
JOIN agent_cash_flows AS acf
	ON acf.cash_flow_id = cf.id
    AND acf.agent_group_id in (
		select cash_flow_group_id from agents WHERE hierarchy_path LIKE CONCAT(@HierarchyPath, '%')
    )
    AND acf.backend_display = 1
LEFT JOIN (
	SELECT wpr.agent_cash_flow_id AS acfid
		, SUM(wo.amount) AS amount
	FROM withdrawal_orders as wo
    JOIN banks AS b ON b.id = wo.bank_id
	JOIN withdrawal_payout_records AS wpr
		ON wpr.withdrawal_order_id = wo.id AND wpr.is_success_payout = 1
        AND wpr.created_at >= @StartTime
        AND wpr.created_at < DATE_ADD(@EndTime, INTERVAL 1 WEEK)
	JOIN cash_flows AS cf ON cf.id = wpr.cash_flow_id
    LEFT JOIN members AS m ON m.id = wo.member_id
	LEFT JOIN agents AS a ON a.id = m.parent AND a.hierarchy_path LIKE CONCAT(@HierarchyPath, '%')
	LEFT JOIN agents AS aa ON aa.id = wo.agent_id AND aa.hierarchy_path LIKE CONCAT(@HierarchyPath, '%')
	WHERE (
		(cf.id NOT IN (23, 43, 68, 82, 98, 108) AND wo.created_at >= @StartTime AND wo.created_at < @EndTime)
		OR
		(cf.id IN (23, 43, 68, 82, 98, 108) AND wpr.callback_time >= @StartTime AND wpr.callback_time < @EndTime)
	)
    AND wo.status IN (4, 24)
	AND (a.id IS NOT NULL OR aa.id IS NOT NULL)
	GROUP BY wpr.agent_cash_flow_id
) AS z ON z.acfid = acf.id
WHERE cf.type = 3 AND cf.status = 1
GROUP BY cf.id, acf.name
ORDER BY cf.id, acf.name;
