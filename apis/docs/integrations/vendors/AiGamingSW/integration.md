# AiGamingSW — 對接心得（實測落差）

> 依據：2026-08-31 dev（TPower staging 商戶 `TPOWER_t`）實測 callback 流量（GCP log `tp-wz3-rd`，玩家 `aigamingsw00000001`，遊戲 luminadrift / dancedancedance）。
> 廠商原文規格見同目錄 `api.md`；本檔只記文件查不到、實測才確立的行為。

## 1. `/bonus` 是獨立新單，不是原投注的 transactionId（文件未載明）

- 實測序列：deduct tx `459092`（amount 0.4，`hasBonus=true`）→ settle tx `459092`（payout 0.16）→ **bonus tx `459093`**（新單號）；deduct `459098` → settle `459098` → **bonus `459099`**。
- bonus 的 `amount` **恆等於** `payoutAmount`（459099：15.5/15.5；459093：0.32/0.32），且 `gameInfo.betAmount = 0.0`——bonus game 無額外投注，`amount` 只是 final amount 的鏡射，**不是**文件宣稱的「bet amount」。
- 落地：bonus 以獨立注單入帳（bet=0、win=payoutAmount），只加款 `payoutAmount`（對齊文件「refer payoutAmount as final amount」）。初版把 bonus 當「同單累加」去找原單，造成回 6（Bet not exists）。
- 與原局的關聯只在 `gameInfo.matchNo`（與原投注相同），無 top-level 欄位可用。
- **free spin 每一轉都送一筆 bonus**（各自獨立 transactionId、`gameInfo.freeSpinLeft` 遞減），未中獎那轉 `payoutAmount=0`、`resultType=LOSE`。`payoutAmount=0` 的 bonus 不建注單（否則產生 bet=0/win=0 全 0 空單灌爆 BR/TBR，2026-09-01 實測 30 筆中 14 筆全 0），只回成功餘額。
- 欄位歸屬（Tim 拍板）：`/bonus`（free spin 遊戲派彩）入 `win`/`win_lose`（屬玩家輸贏/GGR）；`/cashBonus`（純紅利發放）入 `bonus` 活動獎金欄。

## 2. `betTime` / `resultTime` 為 UTC+8，無時區後綴（文件未載明時區）

- 實測 `betTime=2026-08-31T17:39:21.687229933` 對應 GCP entry `09:39:21Z` → 差 8 小時。
- 落地：`parseVendorTime` 以 `Carbon::parse($time, '+08:00')` 解析再轉 UTC。初版當 UTC 存會快 8 小時，直接影響對帳切窗。
- 格式為 ISO8601 帶納秒，與文件宣稱的 `YYYY-MM-DD HH:mm:ss` 不符（`api.md` 附錄亦記）。

## 3. settle 的 `isFirst` / `isEnd` 恆為 false，不可作為結算完成旗標

- 實測三筆 settle（WIN 0.16 / LOSE 0 / LOSE 0）`isEnd` 全為 false，且每個 transactionId 只 settle 一次。
- 落地：settle 一次即鎖定 `status=1`；重送以 `2001`（Bet Already Settled）擋下，該碼依錯誤碼表會讓廠商停止重送。初版以 `isEnd` 決定 status 會讓注單永遠停在未結算。

## 4. 回調基礎行為（實測確認）

- 回調來源 IP：`0.0.0.0`（staging），UA `ReactorNetty/1.0.18`——設定 IP 白名單用。
- 廠商在我方回非 0 / 404 時會重送（實測 getBalance 404 後 6 秒內重打）。
- `hasBonus=true`（deduct / settle 帶）= 之後會有 bonus 回調（以獨立新單號到達）。
- `gameInfo` 為完整盤面 JSON（單筆 2KB+），我方不落 example.invalid（`stripGameInfo`），需要原始盤面時回 GCP log 查。
- 商戶後台登記的 callback URL 一開始是工單上的 `.../sw/ai`，路由改名 `.../sw/aigaming` 後須同步後台設定，否則 404 在 ingress 層、APP log 無痕。
