# 修復單主單骨架

RD 自己發現 bug 開的修復單，主單用這份骨架。填寫規則照 `../../pm-ticket-create/reference/description-schema/_schema-contract.md`（manifest 格式、必填與選填、`??` 待補寫法、紅線都一樣）。

修復單主單寫給 RD 快速切入，不是講經過：直接寫出問題在哪個方法或端點、什麼條件會觸發，方法名、端點路徑、資料表欄位都可以照寫。修法與要改哪些地方寫在開發子單，重現與驗證步驟寫在測試子單（見 `subtask-content.md`）。

- 每段一到三行，只寫事實：不寫怎麼發現的、時間經過、推論過程，不重複其他段已經講過的內容。
- 修復單不論要不要 QA 驗收，一律寫預期結果：bug 的驗收條件一開始就知道，不改寫成「驗收方式」。
- 字數上限照問題單：1,500 字（見 `../../pm-ticket-create/reference/writing-quality.md` 的字數上限）。
- 沒有畫面變動的修復不放示意圖；修完畫面會變的，照 `ticket-artifact` skill 做示意圖，連結放最上面一行。
- 標題照 `../../pm-ticket-create/reference/title-format.md`，主題寫成「模組 > 頁面 - 修復 <描述>」。
- 修不修、怎麼修要等 PM 拍板的修復單，描述最上方（有示意圖時接在示意圖那一行之後）先寫三段，順序不換、用 PM 看得懂的話寫，技術名詞往下放：
  - 「問題是什麼」：一句話講發生了什麼、對玩家或帳務的後果，講明處理的是舊系統還是新系統。
  - 「有多嚴重」：還在發生、有風險但還沒被利用、已止血或不影響金額、已解決，四選一；再寫影響誰、多少錢、有沒有被利用的證據、RD 能做的是否已做完。
  - 「該找誰修」：一張「誰／做什麼／急迫」表，PM 自己要做的那列加粗。

  要 PM 選的題目，每個選項附建議、理由、不做的後果；AI 擬的推薦項標「AI 推薦」，頁尾寫「AI 依工程紀錄給的建議，不代表工程團隊的立場，決定權在 PM」；該 RD 本人拍板過的建議才寫「工程建議」。三段之後才接本骨架的「問題發生原因」等段落。
- 一次修兩個以上互不相干的問題時，改照本檔最後的「一次修多個問題」寫。

```yaml
schema_id: R-bugfix
category: R
title_suffix_options: [修復]

sections:
  - id: mockup
    section_header: null   # 第一段，無 ## 標題
    optional: true
    fields:
      - { name: MOCKUP_LINK, optional: true, type: url, regex: "^https://claude\\.ai/(code/)?artifact/",
          hint: "修完畫面會變才放；沒有畫面變動整段省略" }

  - id: problem
    section_header: "##  問題發生原因"
    fields:
      - { name: PROBLEM, required: true, type: multiline,
          hint: "一到兩句寫出問題的方法或端點與它錯在哪，下面再用條列補「入口：<端點>」「觸發：<條件>」。方法名、端點路徑、資料表欄位用 `code` 標起來。不寫修法" }

  - id: impact
    section_header: "##  影響範圍"
    fields:
      - { name: IMPACT, required: true, type: text_lines,
          hint: "只列受影響的對象、數量、有沒有實際損失，一條一行，例如「TPower：12 筆提款，合計 3,400，已實際少入帳」。查不到的寫 '?? 待補：<缺什麼>'，不估數字" }

  - id: expected
    section_header: "##  預期結果"
    fields:
      - { name: EXPECTED_LIST, required: true, type: text_lines,
          hint: "修完應該看到什麼，一條一行，每條能直接判定過或不過，可以直接寫欄位值（例如 `status=0`）。不寫測試步驟" }

  - id: rollout
    section_header: "##  上線配合事項"
    optional: true
    fields:
      - { name: ROLLOUT_NOTE, optional: true, type: text_lines,
          hint: "只寫需要 RD 以外的人配合的事，每條寫成「- [ ] <上線前｜上線後>：<誰> <做什麼>」，做完由負責的人勾掉。部署順序與命令寫在開發子單" }

  - id: out_of_scope
    section_header: "##  不在本單範圍"
    optional: true
    fields:
      - { name: OUT_OF_SCOPE_LIST, optional: true, type: text_lines,
          hint: "列本單不處理、但不寫會被誤報或沒人跟進的事。每條寫「什麼事＋要找誰／往哪做」，例如「已轉進舊地址的款項處理，要另外向 CCPayment 確認如何處理」。後續方向不能省" }

  - id: pending
    section_header: "##  待確認"
    optional: true
    fields:
      - { name: PENDING_LIST, optional: true, type: text_lines, hint: "只列會擋住動工的項目，一條一行" }
```

---

```markdown
> 示意圖：<MOCKUP_LINK>

##  問題發生原因
<PROBLEM>

##  影響範圍
<IMPACT>

##  預期結果
<EXPECTED_LIST>

##  上線配合事項
<ROLLOUT_NOTE>

##  不在本單範圍
<OUT_OF_SCOPE_LIST>

##  待確認
<PENDING_LIST>
```

---

## 一次修多個問題

一張修復單要修兩個以上互不相干的問題時（例如盤點出 16 項風險），主單改成問題清單，每個問題的細節寫在它自己的開發子單。這種單叫「多問題修復單」，上面的 yaml 骨架不套用。

- 開單前先跟使用者確認要開幾組：必須同一批上線的問題合成一組，其餘一個問題一組。使用者說「13 項問題」，就是 13 項都要開。
- 不論要不要 QA 驗收，每組都建開發子任務並寫好內文，一組改到兩個以上 repo 時每個 repo 各一張。需要 QA 驗收時，每組再建測試子任務。
- 一組跨兩個以上 repo 時，問題發生原因、影響範圍、預期結果只寫在主要 repo 那張開發子單，問題清單連到這張、測試子單也對回這張；其他 repo 的開發子單只寫修法、要改的地方、部署順序，開頭指回主要那張。
- 主單只寫四段：開頭一兩句說明（點問題名稱進開發子單、驗收以開發子單的預期結果為準）、「問題清單」表、「上線配合事項」、「待確認」。主單不寫問題發生原因、影響範圍、預期結果。
- 問題清單表的欄位是「#、問題、優先度、狀態」。問題名稱連到該組的開發子單；狀態寫「已開子單」，和別的問題同一組時寫「已開子單，與問題 N 同一組」。
- 每組開發子單照 `subtask-content.md` 的「多問題修復單」骨架寫，開頭寫「主單：[target/tickets#<主單號>](http://example.invalid:1688/target/tickets/-/work_items/<主單號>)（驗收以本單預期結果為準）」。測試子單開頭寫「主單：[target/tickets#<主單號>](http://example.invalid:1688/target/tickets/-/work_items/<主單號>)；驗收以同組開發子單 [target/ticket_tasks#<開發子單號>](http://example.invalid:1688/target/ticket_tasks/-/work_items/<開發子單號>) 的預期結果為準，這裡只放測試步驟」，每一步對回「開發子單預期結果第 N 條」。
- 子單標題：`[GFF]<模組> - 問題 N：<問題名稱> - 開發`，測試子單結尾換成 ` - 測試`，跨 repo 時結尾寫 ` - 開發(repo名稱)`。同一組寫「問題 1、13：<問題名稱>」。主單標題的站別標記照樣帶上。
- 主單建立時子單還沒有網址：預覽裡問題名稱的連結先寫 `<建立後回填>`，並在預覽的送出步驟列出「子任務建好後，用 `update_issue_description_patch` 把連結回填進主單」。使用者回 `y` 就包含這一步。
