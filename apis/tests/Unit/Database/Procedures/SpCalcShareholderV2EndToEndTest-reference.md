# 分桶（2IN1）試算表參考

參考:https://example.invalid/spreadsheets/d/10n0BRqQ-52wVoxXFY9DNHs7KvQX-xUYGIamKeHgyI4E/edit?gid=0#gid=0

對應測試：`SpCalcShareholderV2EndToEndTest.php` · 核心 SP：`SP_calc_shareholder_temp2` → `SP_calc_shareholder2`

本試算表為 **SLOT 單分類、三代理（A/B/C）** 之 **2IN1 分桶** 情境；測試輸出為**單一合併表**（見 `dumpAgentShareholderSpreadsheetSummary()`）。

---

## 業務規則（試算表前提）

1. **占成模式**：每個分類（`shareholder_category`）可設定 `bet_ratio`（營業額分潤）與 `commission_ratio`（淨利潤分潤）；代理須在各分類配置 **分桶錢包**（`agent_wallets`），**輸贏封頂**依錢包餘額（本表未展開 Settlement 以外之封頂細節）。
2. **總桶負盈利**：若公司該分類 `nett_profit ≤ 0`，**只計算營業額桶**（`total_bet_nett_profit`），**盈利桶**（`total_win_nett_profit`）= 0，差額由公司承擔。對應 SP：`SP_calc_shareholder2` 內 `CASE WHEN t.nett_profit > 0 THEN … ELSE 0 END`。
3. **代理負盈利**：若代理該分類 `game_profit ≤ 0`，不計入 `net_win` 分母（`direct_win` = 0、`direct_win_rate` = 0%），**不參與淨利潤分潤分配**；營業額分潤仍依 **營業額佔比** 計算。對應 `SP_calc_shareholder_temp2`：`CASE WHEN SUM(game_profit) > 0 THEN SUM(game_profit) ELSE 0 END AS win`。

### 公式摘要（SLOT，`share_point` 總計 = 130）

| 項目 | 公式 |
| --- | --- |
| 營業額桶池 | `total_bet_nett_profit = nett_profit × bet_ratio / (bet_ratio + commission_ratio)` |
| 盈利桶池 | `total_win_nett_profit = nett_profit × commission_ratio / (bet_ratio + commission_ratio)`（僅當 `nett_profit > 0`） |
| 代理營業額分潤 | `direct_nett_profit = total_bet_nett_profit × (代理營業額 / 總營業額) × (代理 share_point / 130)` |
| 代理淨利潤分潤 | `direct_win_nett_profit = total_win_nett_profit × (代理 net_win / 總 net_win) × (代理 share_point / 130)` |
| 代理總收益（2IN1） | `direct_nett_profit + direct_win_nett_profit`（同分類加總後再扣 Settlement） |

**範例（盈利桶）**：SLOT 淨利 30,000 → 盈利桶 12,000（40%）；A/B 淨利 +20,000 各一筆、C 淨利 -10,000 忽略 → `net_win` = 40,000；A 權重 = 20,000 / 40,000 × 12,000 × (100/130)。

---

## 公司／遊戲平台組（`summary_shareholder_temps`）

分類比例：**營業額 60%** · **淨利潤 40%** · 總代 `share_point` = **130**


| 游戏平台组 | 营业额 | 奖金支出 | 净利润(全加) | 营业额分润(60%) | 净利润营业额(只算公司贏) | 净利润分润(40%) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| SLOT GAME | 1,000,000.00 | 970,000.00 | 30,000.00 | 18,000.00 | 40,000.00 | 12,000.00 |
| Settlement | 1,000,000.00 | -1,000.00 | -1,000.00 | -600.00 | — | -400.00 |


> **驗算**：`18,000 = 30,000 × 60%`；`12,000 = 30,000 × 40%`；`40,000 = 15,000 + 25,000`（C 的 -10,000 不計入 `net_win`）。

---

## 各代理試算表（2IN1 · SLOT · 含 Settlement 成本 -1,000）


| 代理 | 股份 | 营业额 | 佔比 | 净利润 | 佔比 | 营业额分润(60%) | 净利润分润(40%) | 代理总收益 |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| A代理 | 100/130 | 600,000.00 | 60.00% | 15,000.00 | 37.50% | 8,030.77 | 3,346.15 | 11,376.92 |
| B代理 | 100/130 | 300,000.00 | 30.00% | 25,000.00 | 62.50% | 4,015.39 | 5,576.92 | 9,592.31 |
| C代理 | 100/130 | 100,000.00 | 10.00% | -10,000.00 | 0.00% | 1,338.47 | 0.00 | 1,338.47 |
| **sub-total** | — | **1,000,000.00** | **100.00%** | **30,000.00** | **100.00%** | **13,384.63** | **8,923.07** | **22,307.70** |

> C 代理淨利潤為負，**不參與盈利桶**（淨利潤佔比 0%）。

---

## SLOT 驗算明細（2IN1 · 未扣 Settlement）

| 代理 | 營業額分潤 | 計算式 | 淨利潤分潤 | 計算式 |
| --- | ---: | --- | ---: | --- |
| A | 8,307.69 | `18,000 × 60% × (100/130)` | 3,461.54 | `12,000 × 37.5% × (100/130)` |
| B | 4,153.85 | `18,000 × 30% × (100/130)` | 5,769.23 | `12,000 × 62.5% × (100/130)` |
| C | 1,384.62 | `18,000 × 10% × (100/130)` | 0.00 | C 淨利 < 0 → 佔比 0% |
| **合計** | **13,846.15** | `= 18,000 × (300/130)` | **9,230.77** | `= 12,000 × (300/130)` |

> `300/130` 為三代理 share_point 合計（各 100）相對總代 130 之可分配比例。

### Settlement 分攤（公司 Settlement：營業額桶 -600、盈利桶 -400）

| 代理 | 營業額 Settlement | 淨利潤 Settlement |
| --- | ---: | ---: |
| A | `-600 × 60% × (100/130) = -276.92` | `-400 × 37.5% × (100/130) = -115.38` |
| B | `-138.46` | `-192.31` |
| C | `-46.15` | `0.00`（淨利佔比 0%） |

---

## 測試 seed 對照（規劃）

### 代理與股份


| 試算表 | `agents` | `agent_personal_settings` (95001) | SLOT 營業額 | SLOT `game_profit` |
| --- | --- | ---: | ---: | ---: |
| A 代理 | child A | 100 | 600,000 | 15,000 |
| B 代理 | child B | 100 | 300,000 | 25,000 |
| C 代理 | child C | 100 | 100,000 | **-10,000** |
| 總代 | GA | — | — | — |
| 總代股份 | setting 8001 | **130** | — | — |

### 分類設定（SLOT）


| 欄位 | 值 |
| --- | --- |
| `bet_ratio` | 60 |
| `commission_ratio` | 40 |
| 公司 SLOT `turnover` | 1,000,000 |
| 公司 SLOT `nett_profit` / `game_profit` | 29,000（子代理 30,000 + 總代 Settlement -1,000） |
| 公司 SLOT `net_win` | 40,000（僅正淨利代理加總） |
| `game_spending`（獎金支出） | 970,000 |

### Settlement（後台輸入 · 測試已涵蓋）


| 項目 | 值 |
| --- | --- |
| 寫入 | `summary_shareholder_winloses` · 總代 · `vendor_id` = SLOT 廠商 · `game_profit` = **-1,000** |
| 公司 Settlement 淨影響 | -1,000 |
| 營業額桶調整 | -600 |
| 盈利桶調整 | -400 |

---

## 欄位與 DB / SP 對照


| 試算表欄位 | 測試 / DB 對照 |
| --- | --- |
| 营业额 | `summary_shareholder_winloses.turnover`（`calc_bet = 1`） |
| 奖金支出 | `summary_shareholder_winloses.game_spending` |
| 净利润(全加) | `summary_shareholder_winloses.game_profit` → `summary_shareholder_temps.nett_profit` |
| 净利润营业额(只算公司贏) | `summary_shareholder_temps.net_win` |
| 营业额分润(60%) | `summary_shareholder_temps` 之 `nett_profit × bet_ratio/(bet+commission)`；代理端 → `summary_shareholders.direct_nett_profit`（+ team） |
| 净利润分润(40%) | `total_win_nett_profit`；代理端 → `summary_shareholders.direct_win_nett_profit`（+ team） |
| 佔比（營業額） | `direct_rate` / `team_rate` |
| 佔比（淨利潤） | `direct_win_rate` / `team_win_rate` |
| 代理股份 | `agent_personal_settings.value`（`setting_id = 95001`） |
| 總代股份分母 | `summary_shareholder_temps.share_point`（setting **8001**） |
| Settlement | 後台寫入總代 `summary_shareholder_winloses.game_profit`（與 SLOT `vendor_id` 對應） |

---

## Sources

- `database/procedures/SP_calc_shareholder_temp2.sql`
- `database/procedures/SP_calc_shareholder2.sql`
- `docs/分桶與佔成/分桶流程.md`
