SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @Username = 'Acompany';
SET @DataTime = '2026-06-07 08:00:00';


###########################################################################
# FIND_IN_SET 清單內禁止有空白
SET @TimezoneMinus4Vendors = '16,43,44,53,101';
SET @TimezonePlus0Vendors = '14,15,20,54,69,71,83,97,249';
SET @TimezonePlus7Vendors = '168';

SELECT id, hierarchy_path INTO @AgentId, @HierarchyPath
FROM agents WHERE username = @Username;
SET @StartTimePlus8 = DATE_SUB(@DataTime, INTERVAL 8 HOUR);
SET @EndTimePlus8 = DATE_ADD(@StartTimePlus8, INTERVAL 1 DAY);
SET @StartTimeMinus4 = DATE_ADD(@DataTime, INTERVAL 4 HOUR);
SET @EndTimeMinus4 = DATE_ADD(@StartTimeMinus4, INTERVAL 1 DAY);
SET @StartTimePlus0 = @DataTime;
SET @EndTimePlus0 = DATE_ADD(@StartTimePlus0, INTERVAL 1 DAY);
SET @StartTimePlus7 = DATE_SUB(@DataTime, INTERVAL 7 HOUR);
SET @EndTimePlus7 = DATE_ADD(@StartTimePlus7, INTERVAL 1 DAY);
###########################################################################
SELECT IFNULL(
		r.time_zone,
		CASE
			WHEN FIND_IN_SET(vi.vendor_id, @TimezoneMinus4Vendors) > 0 THEN '-4'
			WHEN FIND_IN_SET(vi.vendor_id, @TimezonePlus0Vendors) > 0 THEN '+0'
			WHEN FIND_IN_SET(vi.vendor_id, @TimezonePlus7Vendors) > 0 THEN '+7'
			ELSE '+8'
		END
	) AS time_zone
	, vi.vendor_id
	, IFNULL(r.bet, 0) AS bet
	, IFNULL(r.win_lose, 0) AS win_lose
	, IFNULL(r.jackpot, 0) AS jackpot
FROM (
	SELECT vi.vendor_id
	FROM agent_vendors AS av
	JOIN vendor_infos AS vi ON vi.id = av.vendor_info_id AND vi.status = 1
	WHERE av.agent_id = @AgentId
	GROUP BY vi.vendor_id
	ORDER BY vi.vendor_id
) AS vi
LEFT JOIN (
	(
		SELECT '+8' AS time_zone, vendor_id, SUM(bet) AS bet
			, SUM(win_lose) AS win_lose
			, SUM(jackpot) AS jackpot
		FROM summary_bet_records as r
		JOIN members AS m ON m.id = r.member_id
		JOIN agents AS a ON a.id = m.parent AND a.hierarchy_path LIKE CONCAT(@HierarchyPath, '%')
		WHERE r.summary_time >= @StartTimePlus8
		AND r.summary_time < @EndTimePlus8
		AND FIND_IN_SET(r.vendor_id, @TimezoneMinus4Vendors) = 0
		AND FIND_IN_SET(r.vendor_id, @TimezonePlus0Vendors) = 0
		AND FIND_IN_SET(r.vendor_id, @TimezonePlus7Vendors) = 0
		GROUP BY r.vendor_id
	) UNION ALL (
		SELECT '-4' AS time_zone, vendor_id, SUM(bet) AS bet
			, SUM(win_lose) AS win_lose
			, SUM(jackpot) AS jackpot
		FROM summary_bet_records as r
		JOIN members AS m ON m.id = r.member_id
		JOIN agents AS a ON a.id = m.parent AND a.hierarchy_path LIKE CONCAT(@HierarchyPath, '%')
		WHERE r.summary_time >= @StartTimeMinus4
		AND r.summary_time < @EndTimeMinus4
		AND FIND_IN_SET(r.vendor_id, @TimezoneMinus4Vendors) > 0
		GROUP BY r.vendor_id
	) UNION ALL (
		SELECT '+0' AS time_zone, vendor_id, SUM(bet) AS bet
			, SUM(win_lose) AS win_lose
			, SUM(jackpot) AS jackpot
		FROM summary_bet_records as r
		JOIN members AS m ON m.id = r.member_id
		JOIN agents AS a ON a.id = m.parent AND a.hierarchy_path LIKE CONCAT(@HierarchyPath, '%')
		WHERE r.summary_time >= @StartTimePlus0
		AND r.summary_time < @EndTimePlus0
		AND FIND_IN_SET(r.vendor_id, @TimezonePlus0Vendors) > 0
		GROUP BY r.vendor_id
	) UNION ALL (
		SELECT '+7' AS time_zone, vendor_id, SUM(bet) AS bet
			, SUM(win_lose) AS win_lose
			, SUM(jackpot) AS jackpot
		FROM summary_bet_records as r
		JOIN members AS m ON m.id = r.member_id
		JOIN agents AS a ON a.id = m.parent AND a.hierarchy_path LIKE CONCAT(@HierarchyPath, '%')
		WHERE r.summary_time >= @StartTimePlus7
		AND r.summary_time < @EndTimePlus7
		AND FIND_IN_SET(r.vendor_id, @TimezonePlus7Vendors) > 0
		GROUP BY r.vendor_id
	)
) AS r ON r.vendor_id = vi.vendor_id
ORDER BY vi.vendor_id;