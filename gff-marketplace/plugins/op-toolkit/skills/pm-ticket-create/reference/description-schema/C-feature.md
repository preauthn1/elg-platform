# C 功能優化 / 修復 schema

段落順序照 PM 成員 Frank 的定義：示意圖、緣由、作法、名詞定義、不在本單範圍、待確認；「上線配合事項」是選填段，放在作法之後。**預期結果不另立章節，緊貼在「作法」裡每一項需求的正下方**，需求與驗收標準分開寫會漂移。測試步驟不寫在主單，寫在測試子單。怎麼寫見 `reference/writing-quality.md`。

例外：RD 自己做、不需要 QA 驗收的單（由 rd-ticket-create 開）不寫「預期結果」，改在每一項需求下方寫選填的 `**驗收方式**`，一兩句說明怎樣算做完；沒寫就整段省略（見 `_schema-contract.md` 規則 9）。

這張單是優化、修復、新增還是重構（下面的 `title_suffix`），決定示意圖是不是必填，也決定字數上限：修復算問題單，上限 1,500 字，其餘三種算需求單，上限 3,000 字（見 `writing-quality.md` 的字數上限）。判斷方式：

- 看標題主題裡有沒有「修復」「優化」「新增」「重構」。剛好出現其中一個，就直接採用，在預覽「字數」那一行寫出採用哪一種，例如「1,200 / 1,500（修復）」。
- 一個都沒有，或出現兩個以上，用 `AskUserQuestion` 問一題「這張單是修問題、新增功能，還是優化既有功能？」，選項三個：「修問題」對應修復，「新增功能」對應新增，「優化或重構既有功能」對應優化。
- 使用者在預覽改了標題的動詞，就照新的動詞重新判斷。

```yaml
schema_id: C-feature
category: C
title_suffix_options: [優化, 修復, 新增, 重構]

sections:
  - id: mockup
    section_header: null   # description 第一段，無 ## 標題
    fields:
      - { name: MOCKUP_LINK,
          required_when: { title_suffix: [優化, 新增, 重構] },
          optional_when: { title_suffix: [修復] },
          type: url,
          regex: "^https://claude\\.ai/(code/)?artifact/",   # 兩種網址都是發布結果會回傳的格式
          placeholder_allowed: "?? 待補示意圖",              # 使用者選擇先開單時可用，預覽標「⚠ 無示意圖」，不算擋下
          hint: "artifact 版示意圖連結；產製與分享規則見 ticket-artifact skill。畫的是整合後要做成的樣子，不是參考站截圖。這張單沒有任何畫面變動時整段省略，優先於上面的 required_when（例如純 bug 修復、後端或文件的改動、內部工具沒有畫面的改動、只讓頁面載入變快的效能優化）" }

  - id: reason
    section_header: "##  緣由"
    fields:
      - { name: REASON, required: true, type: multiline, hint: "最多 3 行，只寫「不做會怎樣」；不寫日期軌跡、決策歷程、自己怎麼查的" }

  - id: approach
    section_header: "##  作法"
    fields:
      - { name: REQUIREMENT_BLOCKS,
          required: true,
          type: multiline,
          hint: "每一項需求一個區塊：### N. <需求標題>、需求說明（做到哪裡為止、資料從哪裡來，用 PM 的角度寫）、**預期結果**（完成後應該看到什麼，能直接判定過或不過，一條一行）。測試步驟寫在測試子單，不寫在這裡。格式見 reference/writing-quality.md「預期結果寫在主單，測試步驟寫在測試子單」；未討論的部分寫 '?? 待補：<缺什麼>'" }

  - id: rollout
    section_header: "##  上線配合事項"
    optional: true
    fields:
      - { name: ROLLOUT_NOTE,
          optional: true,
          type: text_lines,
          hint: "只寫上線前後需要 RD 以外的人配合、或會影響上線時間的事。每條寫成勾選項「- [ ] <上線前｜上線後>：<誰> <做什麼>」，例如「- [ ] 上線前：營運提供收款地址白名單，RD 設定進金流商戶」「- [ ] 分兩階段上線：舊代理後台各站上完，才上 APIs 的排程」。做完由負責的人勾掉。這不是驗收條件；部署順序與命令寫在開發子單；沒有就整段省略" }

  - id: glossary
    section_header: "##  名詞定義"
    optional: true
    fields:
      - { name: GLOSSARY_LIST,
          optional: true,
          type: text_lines,
          hint: "只留會被誤會的名詞，最多三個；能不寫就整段省略" }

  - id: out_of_scope
    section_header: "##  不在本單範圍"
    optional: true
    fields:
      - { name: OUT_OF_SCOPE_LIST,
          optional: true,
          type: text_lines,
          hint: "列本單不處理、但不寫會被誤報或沒人跟進的事。每條寫「什麼事＋要找誰／往哪做」，後續方向不能省" }

  - id: pending
    section_header: "##  待確認"
    optional: true
    fields:
      - { name: PENDING_LIST, optional: true, type: text_lines, hint: "只列會擋住工程師動工的項目，一條一行" }

  - id: attachments
    section_header: "##  附件 / 對話紀錄 / xmind"
    optional: true
    fields:
      - { name: ATTACHMENTS_DETAILS,
          optional: true,
          type: details_block,
          hint: "強制包進 <details><summary>...</summary>...</details>；附件的待補文字用 '?? 待上傳：<檔名>'；參考站截圖放這裡並標明「僅供參考，不照抄」" }
```

---

```markdown
> 示意圖：<MOCKUP_LINK>

##  緣由
<REASON>

##  作法
<REQUIREMENT_BLOCKS>

##  上線配合事項
<ROLLOUT_NOTE>

##  名詞定義
<GLOSSARY_LIST>

##  不在本單範圍
<OUT_OF_SCOPE_LIST>

##  待確認
<PENDING_LIST>

##  附件 / 對話紀錄 / xmind
<ATTACHMENTS_DETAILS>
```
