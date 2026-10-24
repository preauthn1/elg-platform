# D 系統維護 / 跨部門協作 schema

```yaml
schema_id: D-maintenance
category: D
title_suffix_options: [維護, 同步, 重啟, 升級, 切換]

sections:
  - id: background
    section_header: "##  背景"
    fields:
      - { name: BACKGROUND, required: true, type: multiline }

  - id: flow
    section_header: "##  執行流程"
    fields:
      - { name: FLOW_STEPS,
          required: true,
          type: numbered_list,
          hint: "1. <步驟>  負責人: @<user>  預計時間: <when>" }

  - id: rollback
    section_header: "##  回滾方案"
    fields:
      - { name: ROLLBACK, required: true, type: multiline, hint: "若沒有寫 '無 (一次性操作)'" }

  - id: acceptance
    section_header: "##  驗收方式"
    fields:
      - { name: ACCEPTANCE_BLOCKS,
          required: true,
          type: multiline,
          hint: "執行完怎麼確認成功：可直接判定過或不過的預期結果，一條一行。要測試人員動手驗的步驟寫在測試子單；格式見 reference/writing-quality.md「預期結果寫在主單，測試步驟寫在測試子單」" }

  - id: rollout
    section_header: "##  上線配合事項"
    optional: true
    fields:
      - { name: ROLLOUT_NOTE,
          optional: true,
          type: text_lines,
          hint: "只寫上線前後需要 RD 以外的人配合、或會影響上線時間的事。每條寫成勾選項「- [ ] <上線前｜上線後>：<誰> <做什麼>」，例如「- [ ] 上線前：營運提供收款地址白名單，RD 設定進金流商戶」「- [ ] 分兩階段上線：舊代理後台各站上完，才上 APIs 的排程」。做完由負責的人勾掉。這不是驗收條件；部署順序與命令寫在開發子單；沒有就整段省略" }

  - id: notification
    section_header: "##  通知對象"
    fields:
      - { name: STAKEHOLDERS, required: true, type: text_lines, hint: "@user 或部門名稱，每行一個" }
```

---

```markdown
##  背景
<BACKGROUND>

##  執行流程
<FLOW_STEPS>

##  回滾方案
<ROLLBACK>

##  驗收方式
<ACCEPTANCE_BLOCKS>

##  上線配合事項
<ROLLOUT_NOTE>

##  通知對象
<STAKEHOLDERS>
```
