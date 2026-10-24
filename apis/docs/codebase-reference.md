# Codebase Reference

## 關鍵參考檔案

| 用途 | 路徑 |
|---|---|
| 金流商整合技能 | `apis-toolkit` plugin 的 `payment-integration` skill（GFF-Marketplace `plugins/apis-toolkit/skills/payment-integration/SKILL.md`） |
| 遊戲商整合技能 | `apis-toolkit` plugin 的 `game-integration` skill（GFF-Marketplace `plugins/apis-toolkit/skills/game-integration/SKILL.md`） |
| 通用數學／工具方法 | `app/Helpers/Common.php` |
| Enum 索引 | `app/Enums/` |
| 金流商實作範例 | `app/Services/Payment/Providers/EpicPay/` |
| 完整測試範例（含代收＋代付＋回調） | `tests/Unit/Services/Payment/Providers/TopPay/` |
| 測試範例（含 Http::fake） | `tests/Unit/Services/Payment/Providers/YeePay/` |
| 轉帳錢包實作範例 | `app/Vendors/Pg/Driver.php` |
| 單一錢包實作範例 | `app/Vendors/EpicWinSW/Driver.php` |
| 金流商接入完整流程 | `docs/金流接入流程/index.md` |
| 金流通道曝露與切換機制 | `docs/金流通道曝露與切換.md` |
| 遊戲商接入完整流程 | `docs/單一錢包遊戲接入流程/index.md` |

---

## Console Command 分類架構

> 2026-07-27 建立。修補類命令依**設計意圖**分三類，新增命令時對照下表決定放哪個目錄。

| 目錄 | 語意 | 判準 |
|---|---|---|
| `Maintenance/` | **可複用維運工具**，需要時才跑 | 冪等、帶 `--dry-run`／範圍參數／自我檢測；**目標狀態仍會再度發生**（來源未斷、新站 seed 仍產生、相容 fallback 還在讀舊格式） |
| `Guard/` | ⚠️ **非 sync-safe 守護**，`cash-flow:sync` 後必須重跑 | 成果會被 sync 沖掉——sync 靠 name matching 重建 `cash_flow_banks`／`available_banks`／base banks，把補上的對映打回去。**漏跑＝代付直接壞** |
| `Patch/` | **一次性**，各站跑完即待退役 | 來源已斷（config／seed／migration 已改），只需清理存量；命名常綁時間或事故（`*-2026may`） |

現有位置：`app/Console/Commands/CashFlow/{Maintenance,Guard,Patch}/`、`app/Console/Commands/{Maintenance,Patch}/`。

### 判類時的反面教材

**不能**用「最後 commit 日期古老 ＋ 全 repo 零引用」判定一次性——冪等修補工具本來就是寫好放著、
需要時才跑，長期沒人動是正常狀態而非死亡跡象。2026-07-27 的清理盤點初版即因此誤判 4 支。

### docblock 必備欄位

修補類命令（上述三目錄）的 class docblock 一律含下列欄位，缺一不可：

```
【分類】<Maintenance | Guard | Patch> — <一句話定位>

## 發生原因      為什麼會有這個問題（根因，不是現象）
## 修正目的      這支命令做什麼、資料怎麼推導
## 觸發時機      什麼情況下該跑（Guard 類須明寫「sync 後必跑」與漏跑後果）
## 冪等性與重跑安全性   能不能重跑、有哪些保護層（dry-run／confirm／防呆攔截）
## 退役條件      什麼條件達成後這支可以刪（讓下次盤點不必考古）
## 關聯          設計文件、工單、上下游命令
```

**「退役條件」是這套模板的核心**——它讓「這支還要不要留」變成可查證的條件判斷，
而不是每次盤點都得重新考古一遍。

參考範例：`app/Console/Commands/CashFlow/Maintenance/BackfillCashFlowId.php`（Maintenance）、
`app/Console/Commands/CashFlow/Guard/BackfillTpowerPayoutBankCodes.php`（Guard）。

---

## 單一錢包 Controller 架構

新增或修改單一錢包（SW）Controller 時，遵循以下架構：

### 繼承結構

```
BaseController (app/Http/Controllers/External/SingleWallets/BaseController.php)
└── XxxSWController
```

- `BaseController` 分兩層：第一層是回調共通（`$account`／`$member`／`$vendorInfo`、`setUpVendorAccount()`、`getVendorInfo()`、`logRequest()`、`verifyBettability()` 等），**轉帳錢包的 `TransferWallets\NextSpinController` 與 `SpadeGamingController` 也在用**；第二層才是單錢包專屬的 `changeBalance()` 扣帳。
- Controller 需實作 `ShouldVerifyGameBettability` interface（標記需驗證投注資格）。

### 必要屬性

```php
protected int $vendorId = VendorIdEnum::XXXSW->value;
protected string $accountClass = XxxswAccount::class;
protected string $betRecordClass = XxxswBetRecord::class;
protected string $actionPrefix = 'Xxx_SW_request_';
protected string $vendorInfoOperatorField = 'XXX_CODE'; // vendors 表欄位名稱
```

### 核心方法慣例

| 任務 | 使用方法 |
|---|---|
| 查餘額 | `(new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member)` |
| 查帳號 + 設定成員變數 | `$this->setUpVendorAccount(thirdPartyUsername: $uid, whereConditions: [['user_token', $utoken]])` |
| 扣帳 / 入帳 | `$this->changeBalance($this->member, $walletDto, $beforeBetRecord, $afterBetRecord)` |
| 取得帶鎖錢包 | `(new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account)` |

### callAction 攔截模式

所有 SW Controller 統一在 `callAction()` 做 IP 白名單驗證與請求 Log，再呼叫 `parent::callAction()`：

```php
public function callAction($method, $parameters)
{
    try {
        // IP 驗證、Log
        if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
            throw new Exception("IP {$ip} not allow", ErrorCodeEnum::PARAM_ERROR);
        }
        return parent::callAction($method, $parameters);
    } catch (\Throwable $e) {
        return $this->errorResponse(ErrorCodeEnum::SYSTEM_BUSY, $e->getMessage());
    }
}
```

### 參考實作

| 用途 | 路徑 |
|---|---|
| 需驗投注資格 interface | `app/Http/Controllers/External/SingleWallets/Contracts/ShouldVerifyGameBettability.php` |
| BaseController | `app/Http/Controllers/External/SingleWallets/BaseController.php` |
| MegawinSW（新架構範例） | `app/Http/Controllers/External/SingleWallets/MegawinSWController.php` |
| PPSW Controller（舊架構，含 multi-bet） | `app/Http/Controllers/External/SingleWallets/PpswController.php` |
| PPSW Seamless Wallet API 規格整理 | `docs/integrations/vendors/PPSW/api.md` |
| PPSW 對接心得 | `docs/integrations/vendors/PPSW/integration.md` |
| PPSW 整合測試 | `tests/Feature/External/SingleWallets/PPSW/PpswMultiBetTest.php` |
| MegawinSW 整合測試 | `tests/Feature/External/SingleWallets/Megawin/` |

---

## 活動 V2 系統架構

> 完整文件已移至 [`docs/event-v2/`](event-v2/README.md)，包含 API 生命週期、主錢包扣款邏輯、所有 handler class 說明，以及 `event_v2_details` 欄位完整參考（含 DB id 1–14 範例）。

---

## PPSW 特有邏輯

> 以下邏輯為 **PPSW（vendor_id=83）專屬**，不適用其他遊戲商：

- Multi-bet：同一 `roundId` 多筆 bet 需累加到同一筆 BetRecord
- `bonus` 欄位用 `numAdd` 累加，勿覆寫（jackpotWin / bonusWin / promoWin）
- BonusWin 為實際入帳交易（非純通知），依 `roundId` 找既有 BetRecord
- Refund 找不到原始 Bet 時回傳 `error:0`（官方規格）
- 錯誤碼 `7` 會觸發 PP Reconciliation，系統例外應回傳 `120`
- 遊戲不存在回傳 `error:8`（GAME_NOT_FOUND）
- BetRecordStatusEnum：`BET(0)` → `END_ROUND(1)` → `SETTLE(3)` / `REFUND(2)`

---

## 會員活動有兩條軌（查「最後活動時間」前先確認查哪一張表）

兩條軌都還在運作，**不是遷移完成、是並存**；而且兩邊的 class 同名 `UpdateMemberActivity`，靠名字分辨會撈錯。

| | 舊軌 | 新軌（APIs 自建） |
|---|---|---|
| 表 | `member_activities`（歷史）／`member_activities_latest`（每人最新一列） | `member_statistics`／`member_hourly_statistics` |
| 寫入者 | **獨立部署專案 RD-Studio-01 的排程**（`update_member_activity`、`update_member_activities_latest`），APIs 只納管 schema | APIs 的 `app/Jobs/UpdateMemberActivity.php`（即時 job，寫 `last_activity_at`／`last_bet_at`） |
| 讀取側 | 舊 `API_Agent` 的 `Services/Members/InactiveService.php`（代理後台「會員活動紀錄」選單） | `app/Services/Agent/MemberActivityFilterService.php` |

⇒ 問題來自舊後台就查舊軌、來自 APIs 就查新軌。另外查「DB 內誰寫某張表」時，Laravel Model 走慣例命名（`MemberActivity` → `member_activities`），只用表名字串搜會漏，需併 class 名一起搜。

---

## 會被全量重建沖掉的補償命令

有一類命令補上的資料，會在 `cash-flow:sync` 或 `agent:add-agent-cash-flow` 全量重建時被清掉。跑完重建卻沒重跑這些命令，就等於回到缺陷狀態。這些命令都冪等、預設不寫入，重跑安全。

**判準＝該表的「全量重建者」是誰**，不是「誰會寫這張表」（`cash_flow_banks` 至少有六支命令會寫）：

- `cash_flow_banks`（代付銀行對照表）的重建者是 `cash-flow:sync`。`SyncCashFlowsCommand` 會先把該金流商所有 `cash_flow_banks` 設為 `status=0`，再只把設定檔裡比對得到的銀行重新啟用 ⇒ **設定檔以外補上的列會停在 `status=0` 而失效**。
- `agent_cash_flows.info.available_banks`（金流商戶可代付的銀行清單）的重建者是 `agent:add-agent-cash-flow`，`cash-flow:sync` 不碰它。手動補的項目不在來源資料裡，同樣會被沖掉。
- `cash-flow:auto-sync` 會依序呼叫上面兩支，所以跑它等於兩邊都重建。

**清單在哪**：本檔不維護清單，請看下面兩處。

- `app/Console/Commands/CashFlow/Guard/` 目錄的意思就是「非 sync-safe 的補償命令」。每支命令的 class docblock 寫明補什麼、什麼時候要重跑、漏跑的後果與退役條件，規則以 docblock 為準。
- 跨命令的對照清單（有哪些重建面、每支補償命令對應哪個重建者）在 `docs/未完成計劃/金流商架構重構/銀行收斂/sync全量重建面與補償命令.md`。

**`provider_bank_code` 的例外：人工覆寫標記**

- `provider_bank_code` 是送給三方的銀行碼。sync 重建既有的 `cash_flow_banks` 列時，會把它改回設定檔的值。
- 例外是 `manual_override_at`（人工覆寫標記）有值的列：sync 不改這一欄，只在設定檔的值和現值不同時印出提示（`SyncCashFlowsCommand::createCashFlowBankRelation()`；欄位說明見 `CashFlowBank` 的 docblock）。
- `bank:fix-cfb-provider-code` 與 `bank:reactivate-cfb-rows` 寫入時會一併填這個標記。`bank:fix-mismatch --collision-keep` 的手動挑碼不填，所以挑過的碼在下次 sync 仍會被改回設定檔的值。
- 標記只保護 `provider_bank_code` 這一欄，不保護整列。sync 起手照樣把列設成 `status=0`，名稱比對不中的列重建後會停在停用，要用 `bank:reactivate-cfb-rows` 開回來。

**要怎麼做**：跑完 `cash-flow:sync`、`cash-flow:auto-sync` 或 `agent:add-agent-cash-flow` 之後，照上面兩處清單逐支確認要不要重跑。**新寫的命令若寫入會被全量重建的資料**，要放進 `Guard/` 目錄，在 docblock 標【分類】Guard 與「⚠️ 非 sync-safe」、寫明觸發時機與退役條件，並登記進上述對照清單。

## 稽核批次命令「執行當下的判斷對不對」

**不能拿「現在的狀態」直接當證據**，兩個方向都會失真：事後被人工修好 ⇒ 看起來正常但曾經壞過（假陰性）；事後因無關原因改變 ⇒ 看起來被咬但與命令無關（假陽性）。

本庫多數業務表沒有欄位異動歷史，過去的值讀不到。可用的代理判準是「從命令執行那一刻起沒被改過」：

```sql
m.created_at <= l.created_at   -- 命令執行時它已存在
m.updated_at <= l.created_at   -- 從那一刻起沒被改過
m.status      = 1              -- 它現在是啟用的
```

一列從時間 T 起未被修改，則它現在的值＝T 當下的值 ⇒「現在 `status=1`」＋「T 後未動」可推出「T 當下 `status=1`」。

🔴 **成立前提：`updated_at` 必須每次寫入都真的會動。** 這些命令多用 query builder 的 `update()`，**不會**像 Eloquent 那樣自動補 `updated_at`，所以承重的是 MySQL 欄位層的 `on update CURRENT_TIMESTAMP`。用在別的表前先查 `information_schema.COLUMNS` 的 `EXTRA` 確認。

🔴 **已知反例：`cash_flow_banks.updated_at` 沒有 `on update CURRENT_TIMESTAMP`**（多站實查皆然）⇒ **本判準不可用於該表**，硬用會靜默失效。`banks`／`withdrawal_orders`／`member_bank_accounts`／`realname_validation_requests` 則都有。

⭐ 方向性上只問「可證明正確」：回報「證不出來」的筆數，`= 0` 代表每筆都找得到證人（強結論）；`> 0` 只代表證不出來、不等於錯。反過來找「可證明錯誤」弱得多——找不到錯不代表沒錯。

## 排程有沒有按時跑：查 `command_logs`，不是 `schedule_logs`

- 新平台的排程由 `ScheduleProvider` 與 `CommandProvider` 寫入 **`command_logs`**；`start_time` 是觸發當下，分秒對齊排程定義。
- 🔴 **`schedule_logs` 是舊 API_Agent 的表，新平台完全不寫**（`ScheduleLog` model 是空殼、無寫入碼）。拿新排程定義去對 `schedule_logs.start_time` 必然對不上——那是對錯表，不是排程沒跑。
- **每次觸發會產生兩筆**：一筆帶 `cronjob='<排程定義>'` ＋ `CRON_` trace（排程事件），一筆 **`cronjob` 為 `NULL`** ＋ `ART_` trace（命令本體）。🔴 撈後者要用 `whereNull('cronjob')`，寫成 `where('cronjob', '')` 會回 0 筆。
- `status`：`1` 成功、`-1` 失敗、`-2` 跳過、`0` 未收到 finish（手動中斷常見）。
- ⚠️ 已有 `archive:command_logs` 每日截斷舊資料 ⇒ **別用「總列數 ÷ 排程數」推算歷史區間**。
