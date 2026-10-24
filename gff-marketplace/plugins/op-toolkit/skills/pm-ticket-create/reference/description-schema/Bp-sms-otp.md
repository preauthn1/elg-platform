# B' 簡訊 / OTP / WhatsApp / 三方訊息服務 schema

```yaml
schema_id: Bp-sms-otp
category: Bp
title_suffix_options: [接入, 配置, 優化, 修復]

sections:
  - id: tg
    section_header: null
    fields:
      - { name: TG_NAME, required: true, type: text }
      - { name: TG_URL,  required: true, type: url, regex: "^https?://t\\.me/" }

  - id: api_creds
    section_header: "##  API 帳密"
    fields:
      - { name: API_BASE_URL,   required: true, type: url }
      - { name: API_USERNAME,   required: true, type: text }
      - { name: API_PASSWORD,   required: true, type: secret }
      - { name: API_TOKEN,      optional_with_placeholder: true, type: secret }

  - id: backoffice_login
    section_header: "##  後台帳密"
    fields:
      - { name: BO_URL,         required: true, type: url }
      - { name: BO_USERNAME,    required: true, type: text }
      - { name: BO_PASSWORD,    required: true, type: secret }

  - id: countries
    section_header: "##  支援國家 / 國碼"
    fields:
      - { name: COUNTRY_LIST,   required: true, type: text_lines, hint: "每行: <國家> (+<國碼>) - <備註>" }

  - id: billing
    section_header: "##  計費規則"
    fields:
      - { name: BILLING_UNIT,   required: true, type: text, hint: "每則訊息 / 每次 OTP / 每分鐘" }
      - { name: BILLING_RATES,  required: true, type: text, hint: "依國家分段費率；如有量級折扣一併列出" }
      - { name: BILLING_CURRENCY, required: true, type: text }

  - id: api_doc
    section_header: "##  API 文件"
    fields:
      - { name: API_DOC_LINK, required: true, type: text }

  - id: callback
    section_header: "##  Callback / 送達回報（Delivery Report）"
    optional: true
    fields:
      - { name: CALLBACK_URL,  optional: true, type: url }
      - { name: CALLBACK_SPEC, optional: true, type: multiline }

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

##  API 帳密
API Base URL: <API_BASE_URL>
Username: <API_USERNAME>
Password: [REDACTED]
Token: [REDACTED]

##  後台帳密
BO: <BO_URL>
Username: <BO_USERNAME>
Password: [REDACTED]

##  支援國家 / 國碼
<COUNTRY_LIST>

##  計費規則
單位: <BILLING_UNIT>
幣別: <BILLING_CURRENCY>
費率:
<BILLING_RATES>

##  API 文件
<API_DOC_LINK>

##  Callback / 送達回報（Delivery Report）
URL: <CALLBACK_URL>

<CALLBACK_SPEC>

##  預期結果
<ACCEPTANCE_BLOCKS>

##  上線配合事項
<ROLLOUT_NOTE>

##  注意事項
<NOTES_LIST>
```
