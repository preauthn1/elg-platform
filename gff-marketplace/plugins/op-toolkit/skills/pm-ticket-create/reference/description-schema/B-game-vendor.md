# B 遊戲商 schema

```yaml
schema_id: B-game-vendor
category: B
title_suffix_options: [接入, 配置, 優化, 修復]
wallet_type_required: true   # 必須先收 questions.yaml Q2b_wallet_type

sections:
  - id: tg
    section_header: null
    fields:
      - { name: TG_NAME, required: true, type: text, hint: "<廠商>-<站名> <幣別> 對接群 / 或 <廠商> x <站名> API" }
      - { name: TG_URL,  required: true, type: url,  regex: "^https?://t\\.me/" }

  - id: wallet_overview
    section_header: "##  錢包類型"
    fields:
      - { name: WALLET_TYPE_LABEL, required: true, type: text, hint: "單錢包(直對) / 單錢包(平台) / 轉帳錢包，由 Q2b 自動填" }

  - id: merchant_info
    section_header: "##  正式商戶資訊"
    fields:
      - { name: BO_URL,         required: true,  type: url, hint: "BO 正式站 URL" }
      - { name: AGENT_ID,       required: true,  type: text }
      - { name: AGENT_PASSWORD, required: true,  type: secret }
      - { name: API_TOKEN,      required: true,  type: secret }
      - { name: API_URL,        required: true,  type: url }
      - { name: ACCOUNT_PREFIX, required: true,  type: text, hint: "會員帳號 prefix (例: JB / GFFTEST)" }
      - { name: CURRENCY,       required: true,  type: text, hint: "支援幣別；多幣別逗號分隔" }
      - { name: CURRENCY_RATIO, required_when: { wallet_type: TW }, type: text, hint: "Currency 換算比 (轉帳錢包必填)" }

  - id: callback
    section_header: "##  Callback URL（我方提供給廠商）"
    required_when: { wallet_type: [SW_DIRECT, SW_PLATFORM] }   # 單錢包才需要
    fields:
      - { name: CALLBACK_URL_PROD,    required: true, type: url }
      - { name: CALLBACK_URL_STAGING, optional_with_placeholder: true, type: url }
      - { name: CALLBACK_SIGN_RULE,   required: true, type: multiline, hint: "簽名/Header/Body 規格；通常寫『詳見 API 文件』" }

  - id: ip_whitelist
    section_header: "##  IP 白名單"
    fields:
      - { name: IP_OUT_LIST, required: true, type: text_lines, hint: "我方出口 IP (callback 來源 / API 呼叫端)，請廠商加白" }
      - { name: IP_IN_LIST,  required: true, type: text_lines, hint: "廠商 IP (回調 / 廠商主動呼叫我方)，我方加防火牆" }

  - id: api_doc
    section_header: "##  API 文件"
    fields:
      - { name: API_DOC_LINK,    required: true, type: text, hint: "URL 或 '?? 待上傳：<filename>.pdf' (附訪客帳密如有)" }
      - { name: API_DOC_GUEST,   optional_with_placeholder: true, type: text, hint: "guest 帳密 / 訪客閱讀方式" }

  - id: game_codes
    section_header: "##  遊戲 / 桌子代碼"
    optional: true
    fields:
      - { name: GAME_CODE_TABLE, optional: true, type: text, hint: "代碼 : 名稱，每行一筆" }

  - id: acceptance
    section_header: "##  預期結果"
    fields:
      - { name: ACCEPTANCE_BLOCKS,
          required: true,
          type: multiline,
          hint: "每一項需求寫「完成後應該看到什麼」，要能直接判定過或不過，一條一行。測試步驟不寫在主單，寫在測試子單；格式見 reference/writing-quality.md「預期結果寫在主單，測試步驟寫在測試子單」" }

  - id: rollout
    section_header: "##  上線配合事項"
    optional: true
    fields:
      - { name: ROLLOUT_NOTE,
          optional: true,
          type: text_lines,
          hint: "只寫上線前後需要 RD 以外的人配合、或會影響上線時間的事。每條寫成勾選項「- [ ] <上線前｜上線後>：<誰> <做什麼>」，例如「- [ ] 上線前：營運提供收款地址白名單，RD 設定進金流商戶」「- [ ] 分兩階段上線：舊代理後台各站上完，才上 APIs 的排程」。做完由負責的人勾掉。這不是驗收條件；部署順序與命令寫在開發子單；沒有就整段省略" }

  - id: notes
    section_header: "##  注意事項"
    optional: true
    fields:
      - { name: NOTES_LIST, optional: true, type: text_lines }
```

---

```markdown
TG群: [<TG_NAME>](<TG_URL>)

##  錢包類型
<WALLET_TYPE_LABEL>

##  正式商戶資訊
BO 正式站: <BO_URL>
AgentID : <AGENT_ID>
Password : [REDACTED]
ApiToken : [REDACTED]
Api URL : <API_URL>
Account Prefix : <ACCOUNT_PREFIX>
Currency : <CURRENCY>
Currency 換算比 : <CURRENCY_RATIO>

##  Callback URL（我方提供給廠商）
正式站: <CALLBACK_URL_PROD>
測試站（Staging）: <CALLBACK_URL_STAGING>

簽名 / Header 規格:
<CALLBACK_SIGN_RULE>

##  IP 白名單

我方出口 IP（請廠商加白名單）:
<IP_OUT_LIST>

廠商 IP（我方加防火牆白名單）:
<IP_IN_LIST>

##  API 文件
<API_DOC_LINK>
<API_DOC_GUEST>

##  遊戲 / 桌子代碼
<GAME_CODE_TABLE>

##  預期結果
<ACCEPTANCE_BLOCKS>

##  上線配合事項
<ROLLOUT_NOTE>

##  注意事項
<NOTES_LIST>
```
