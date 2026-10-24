# A 金流商 schema

```yaml
schema_id: A-payment
category: A
title_suffix_options: [配置, 接入, 優化, 修復, 重構]
sections:
  - id: tg
    section_header: null   # 首行，無 ##
    fields:
      - { name: TG_NAME, required: true,  type: text, hint: "<廠商>-<站名> <幣別> 對接群" }
      - { name: TG_URL,  required: true,  type: url,  regex: "^https?://t\\.me/" }

  - id: currency_rate
    section_header: "##  幣別與匯率"
    fields:
      - { name: CURRENCY,    required: true, type: text, hint: "MYR / USDT / IDR ..." }
      - { name: RATE,        required: true, type: text, hint: "1:1 / 1:1000 (法幣:平台點數)" }

  - id: merchant_info
    section_header: "##  正式商戶資訊"
    fields:
      - { name: MERCHANT_PORTAL_URL, required: true,  type: url }
      - { name: API_BASE_URL,        required: true,  type: url }
      - { name: MERCHANT_ID,         required: true,  type: text }
      - { name: API_KEY,             required: true,  type: secret }
      - { name: SECRET_KEY,          required: true,  type: secret }

  - id: backoffice_login
    section_header: "##  後台帳密"
    fields:
      - { name: BO_USERNAME, required: true, type: text }
      - { name: BO_PASSWORD, required: true, type: secret }
      - { name: BO_2FA_NOTE, optional_with_placeholder: true, type: text, hint: "如有 2FA 強制 / 備份碼遺失不補等限制" }

  - id: rates_limits
    section_header: "##  費率與限額"
    repeatable: true   # 通常分 代收 / 代付 / EW / QR 多 channel
    fields:
      - { name: CHANNEL_GROUP, required: true, type: enum, values: ["代收", "代付", "代付出款"] }
      - { name: CHANNEL_ROWS,  required: true, type: text, hint: "每行: <channel> - <費率>，最低收費 X，最低 Y、最高 Z" }
      - { name: DAILY_CAP,     optional_with_placeholder: true, type: text, hint: "日累計上限 + 超過聯絡誰" }

  - id: api_doc
    section_header: "##  API"
    fields:
      - { name: API_DOC_LINK, required: true, type: text, hint: "URL 或 '?? 待上傳：<filename>.pdf'" }

  - id: callback_spec
    section_header: "##  Callback 規格"
    fields:
      - { name: CALLBACK_DEPOSIT, optional_with_placeholder: true, type: multiline, hint: "存款 callback；通常寫『詳見 API 文件』" }
      - { name: CALLBACK_WITHDRAW, required: true, type: multiline, hint: "代付 callback 通常 API 文件未列，須手動補：request 格式、簽名規則、Header、status mapping" }

  - id: ip_whitelist
    section_header: "##  IP 白名單"
    fields:
      - { name: IP_OUT_LIST, required: true, type: text_lines, hint: "我方出口 IP（請廠商加白），每行一個 IPv4" }
      - { name: IP_IN_LIST,  required: true, type: text_lines, hint: "廠商回調 IP（我方防火牆加白），每行一個 IPv4" }

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
      - { name: NOTES_LIST, optional: true, type: text_lines, hint: "2FA / iframe / 不支援功能 / 站方限制等，每行一條" }
```

---

```markdown
TG群: [<TG_NAME>](<TG_URL>)

##  幣別與匯率
結算幣別: <CURRENCY>
法幣 / 平台點數: <RATE>

##  正式商戶資訊
商戶後台網址
<MERCHANT_PORTAL_URL>

API Url
<API_BASE_URL>

<MERCHANT_ID> ( <CURRENCY> )
Api Key : <API_KEY>
Secret Key : <SECRET_KEY>

##  後台帳密
Username: <BO_USERNAME>
Password: [REDACTED]
<BO_2FA_NOTE>

##  費率與限額

<CHANNEL_GROUP 重複區塊：每個 group 一個 ### 子標題 + CHANNEL_ROWS>

日累計代付上限: <DAILY_CAP>

##  API
<API_DOC_LINK>

##  Callback 規格

### 存款
<CALLBACK_DEPOSIT>

### 代付
<CALLBACK_WITHDRAW>

##  IP 白名單

我方出口 IP（請廠商加白名單）:
<IP_OUT_LIST>

廠商回調 IP（我方加防火牆白名單）:
<IP_IN_LIST>

##  預期結果
<ACCEPTANCE_BLOCKS>

##  上線配合事項
<ROLLOUT_NOTE>

##  注意事項
<NOTES_LIST>
```
