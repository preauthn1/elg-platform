# apis-toolkit 白標 registry（連線 + 查詢範疇）

白標 **連線（connectionId）與查詢範疇（rootAgentId）** metadata 的單一真實來源，服務同 plugin 的 `whitelabel-container-exec`、`whitelabel-db-query`、`list-sbr-corrections`、`reconcile-bet-records`，取代「從 `config/database.php` + `.env` 推導」與各 skill 硬寫子樹 root 的舊耦合。

> `gcp-log-troubleshoot` 的白標→GCP project 對照**內嵌於該 skill 的「白標 → GCP project 對照表」節、各自獨立維護**，不讀本 registry；新增/異動白標需兩處各自同步。

## 雙平面（安全約束 D10）

```
白標 registry（apis-toolkit）
├─ Metadata 平面（可進 git、隨 plugin 分發）→ registry/whitelabels.metadata.yaml
│    canonical 名 / 別名[] / connectionId / env / rootAgentId / rootAgentUsername
│    / testRootAgentId / testRootAgentUsername / sharesConnection / dbcPort / database
│    消費者：whitelabel-container-exec（connection/別名 的 SSOT）；
│    　　　　bin/mysql-mcp.js（dbcPort / database → dbc 代理路徑；null → 舊路徑）；
│    　　　　查詢/對帳（whitelabel-db-query/list-sbr/reconcile）的**白標層 scope 取自 rootAgentId**
│    　　　　（查詢一律以此切 hierarchy 子樹範疇；hierarchy_path 由該 id 在 DB 衍生、不硬寫）；
│    　　　　長尾污染樹 census / 會員數 / vendor_info_id 等 live 查證資料仍自持於
│    　　　　whitelabel-db-query/SKILL.md 範疇規則段、不在本 registry
└─ Secret 平面（永不進 git）
     各白標實際 DB host/port/user/password——**只剩 dbcPort: null 的白標還需要**
     解析來源：.env（現況）/ plugin userConfig（per-dev）/ secret manager —— 屬 db-switch 子設計（spec §10）
     dbcPort 非 null 的白標無 secret 平面：host 固定 0.0.0.0、port 公開、無密碼，
     身分由 dbc 代理層以個人 Google 帳號 IAM 完成（見 ../README.md「dbc 前置」）
```

> ⚠️ **任何 DB 連線機密永不進 git**。本 plugin 的 `.gitignore` 守住 secret 檔名樣式；新增白標機密請走 secret 平面，不要寫進 `registry/`。
> secret 平面檔案請命名為 `*.secret.yaml` 或 `*.local.yaml`（會被 `.gitignore` 自動忽略）；任何含 `secret` 的檔名亦會被忽略。

## metadata schema（每白標）

```yaml
<canonical>:
  canonical: <string>          # 標準名（= key）
  aliases: [<string>, ...]     # 口語 / 拼錯別名
  connectionId: <string>       # Laravel connection 識別名
  env: production|test         # 環境類型
  rootAgentId: <int|null>      # 業務子樹 root agent id（env=test 為 null）——查詢一律以此切 hierarchy 範疇
  rootAgentUsername: <str|null># 對應 agents.username（DB 反查/顯示依據；env=test 為 null）
  testRootAgentId: <int|null>  # 測試代理 id（env=test 為 null）——正式環境商戶配置的測試掛點，必直接掛在正式代理下（agents.parent==rootAgentId）
  testRootAgentUsername: <str|null> # 對應測試代理 agents.username（env=test 為 null）
  sharesConnection: <bool>     # connectionId 是否被 ≥2 白標共用（多子樹）；須與實況一致（checker 驗）
  dbcPort: <int|null>          # dbc 本機代理 port（sre/dbc instances.yaml：34xx 從庫、35xx 主庫）；null＝dbc 未涵蓋、mysql-mcp.js 走容器/secrets 舊路徑。同 connectionId 須同值（checker 驗）
  database: <string>           # 該 connection 的 DB 名稱（safe-token；非機密，IAM 使用者 SHOW DATABASES 可見）。同 connectionId 須同值（checker 驗）
  note: <string?>              # 選填；人類備註（如 Dev 的「禁止互轉」）——checker 容許額外欄位
```

## 消費契約（本骨架保證）

1. 存在一份**非機密** metadata registry，作為白標清單唯一來源（DB connection 面）；直接依賴者讀它、不再 parse Laravel config。
2. metadata 與 secret 嚴格分平面。
3. 每白標至少含 `{ canonical, aliases[], connectionId, env, rootAgentId, rootAgentUsername, testRootAgentId, testRootAgentUsername, sharesConnection, dbcPort, database }`。
4. **查詢一律以白標的 `rootAgentId` 切 hierarchy 子樹範疇**（`hierarchy_path` 由該 id 在 DB 衍生、不硬寫），藉此排除同 DB 的其他子樹（長尾污染樹、RD 測試樹）。消費 skill 對「未指定白標」須 **fail-closed**（abort，除非明確要求整庫 census 並於輸出標示；見 `list-sbr-corrections` 實作）；`sharesConnection: true`（多白標共用 DB，如 VWorld/WTT）尤須嚴守。

## 讀取協議（同 plugin 相對路徑）

```
consumer skill 與本 registry 同屬 apis-toolkit plugin：
  Read <skill base dir>/../../registry/whitelabels.metadata.yaml
毋需 claude plugin list / 跨 plugin resolver——同 plugin 相對路徑天然指向
提供該 skill 的同一安裝實例（無版本分歧問題）。
讀取/解析失敗（檔缺 / YAML 壞）→ fail-closed：停下、告知 registry 不可用，
不猜 connection、不 fallback 預設。
```

resolver 演進只改本段；consumer skill §0 為摘要、引本段為權威。

## 驗證分層（兩支腳本互補）

| 腳本 | 驗什麼 | 何時跑 | 進 CI？ |
|---|---|---|---|
| `../scripts/check-registry.php` | **結構/型別不變式**：必填欄位、canonical==key、connectionId safe-token、別名全域碰撞、rootAgent／testRootAgent 型別、sharesConnection 與連線次數一致、規模閘 | 本機 / pre-commit / CI | **是**（php 實作，phpbase 鏡像鐵定有；根 `scripts/validate.sh §8` 直接跑成硬閘） |
| `../scripts/verify-registry-live.py` | **live 語意**：每 production 白標的正式代理（`rootAgentId` 存在、`agents.username` 吻合；位置不強制 Admin 直屬）＋測試代理（`testRootAgentId` 存在、`agents.username` 吻合、`agents.parent == rootAgentId`）| DB 可達時（pre-release）；`python3 ../scripts/verify-registry-live.py` | 否（需 docker + 連生產讀庫） |

> 結構驗證擋不住「打錯但合法」的 rootAgentId／testRootAgentId（指到另一真實 agent 會通過型別檢查、卻把 scope 導向錯子樹或把商戶配置掛到錯代理）；故 rootAgentId／testRootAgentId 異動或新增白標後，DB 可達時須跑 live 驗證確認。

> **pre-commit hook 自動串接**：repo 根 `.githooks/pre-commit.sh` 在 `whitelabels.metadata.yaml` 被 staged 時自動跑兩支——`check-registry.php`（與 CI 同一支）失敗即擋；`verify-registry-live.py` 連不到 DB 只警告放行（exit 3）、值不一致才擋（exit 1）。安裝見 `CONTRIBUTING.md` 開發環境段。沒動 registry 的 commit 不受影響。

## 相關設計

跨 plugin 多 scope resolver 已退場、gcp-log 對照表改為 skill 內嵌。`log` 為本機 _log DB、非白標、不入 registry。
