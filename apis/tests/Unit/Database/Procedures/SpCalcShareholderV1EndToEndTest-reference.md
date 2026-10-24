# 分桶（1代 SP）試算表參考

對應測試：`SpCalcShareholderV1EndToEndTest.php` · 核心 SP：`SP_calc_shareholder_temp` → `SP_calc_shareholder` → `SP_shareholder_payout`

本試算表與 {@see SpCalcShareholderV2EndToEndTest-reference.md} **共用同一組 seed 數據**（SLOT · 三代理 · Settlement -1,000），但：

- 分類比例設為 **`bet_ratio = 100`、`commission_ratio = 0`**（100% 投注分紅、0% 業績分紅）
- 1 代 SP 僅依 **營業額佔比** 分配 **`summary_shareholder_temps.nett_profit` 全池**
- 理論上應等同 2 代 SP 在 **60/40 2IN1** 下 **`direct_nett_profit + direct_win_nett_profit` 合計**

---

## 公式（1 代 · SLOT · `share_point` 總計 = 130）

| 項目 | 公式 |
| --- | --- |
| 公司可分配池 | `total_nett_profit = summary_shareholder_temps.nett_profit`（含 Settlement） |
| 代理分潤 | `direct_nett_profit = total_nett_profit × (代理營業額 / 總營業額) × (代理 share_point / 130)` |
| 派彩 | `SP_shareholder_payout` 加總 `direct_nett_profit`（+ 上級 `team_nett_profit`，本 seed 為平層子代） |

---

## 各代理預期（2 代 bet_ratio=100、commission_ratio=0 等價）

| 代理 | 股份 | 营业额 | 佔比 | 代理总收益（`direct_nett_profit`） |
| --- | --- | ---: | ---: | ---: |
| A代理 | 100/130 | 600,000.00 | 60.00% | 13,384.62 |
| B代理 | 100/130 | 300,000.00 | 30.00% | 6,692.31 |
| C代理 | 100/130 | 100,000.00 | 10.00% | 2,230.77 |
| **sub-total** | — | **1,000,000.00** | **100.00%** | **22,307.70** |

驗算（A）：`29,000 × 60% × (100/130) = 13,384.6154`（SP `TRUNCATE(…,4)`）。

> 注意：若 2 代為 **60/40 2IN1**，各代理「代理总收益」會是 **11,376.92 / 9,592.31 / 1,338.47**（依淨利佔比分潤），但 **sub-total 仍為 22,307.70**。1 代 100/0 與 2 代 100/0 逐代理一致；與 2 代 60/40 僅總計相同、分配不同。

---

## 公司彙總（`summary_shareholder_temps`）

| 欄位 | 值 |
| --- | ---: |
| `turnover` | 1,000,000.00 |
| `nett_profit` | 29,000.00 |
| `share_point` | 130 |

---

## Sources

- `database/procedures/SP_calc_shareholder_temp.sql`
- `database/procedures/SP_calc_shareholder.sql`
- `database/procedures/SP_shareholder_payout.sql`
- `docs/分桶與佔成/分桶流程.md`（2 代對照）
- `tests/Unit/Database/Procedures/SpCalcShareholderV2EndToEndTest-reference.md`
