# Description Schema Contract

> **Read this before using any `*-*.md` schema in this folder.**

## Skill 必須遵守的填寫規則

1. **每個 schema 是 fenced markdown 骨架 + 一份欄位 manifest (YAML in front-matter)**。
2. Skill 收集到 routing answers (questions.yaml) 後，依 `category → schema` 對應載入這份檔案。
3. 骨架內所有 `<PLACEHOLDER>` 必須對應到 manifest 內的同名欄位定義。
4. **章節順序、章節標題、章節數量不可動**。Skill 只能填欄位值，不能新增或刪除 `##` 章節。
5. 若 manifest 標 `required: true` 的欄位無值 → 該欄位在預覽**必須**標 `✗ 必填未填`，算進「擋下的項目」，送出時拒絕寫入。填了 `?? 待補：<缺什麼>` 算有值：預覽標 `⚠ 待補`，不算擋下；連待補文字都沒有才算無值。
6. 若 manifest 標 `optional: true` 的欄位無值 → 該章節整段省略（不要留 `<PLACEHOLDER>` 在 description 內）。
7. 若 manifest 標 `optional_with_placeholder: true` 的欄位無值 → 留 `?? 待補：<缺什麼>` 純文字，例如 `?? 待補：測試站網址`（不可包進 markdown link / image 語法）。只寫 `??` 的話，讀單的人不知道缺的是什麼。
8. **內文怎麼寫，由 `reference/writing-quality.md` 規定。** 那份文件管的是字數上限、精簡八條、
   預期結果的格式，以及內容慣例（正負值上色、單號不加千分位、日期不知道就留空）。
   這裡的各份 schema 只定義有哪些欄位，**不重複規定寫法**。
9. **預期結果所有 schema 都必填**：
   - 主單用 PM 的角度寫。每一項需求都要寫「完成後應該看到什麼」，要能直接判斷「過」或「不過」。這是 PM 要看的改動結果，也是測試人員唯一的驗收標準。
   - C 功能優化類：預期結果**不另立章節**，寫在「作法」裡每一項需求的正下方（欄位 `REQUIREMENT_BLOCKS`）。
   - A、B、Bp 三類：寫在各自的 `預期結果` 章節；D 類寫在 `驗收方式` 章節。
   - **測試步驟不寫在主單**，寫在測試子單，每一步對回主單的預期結果。PM 開單時已經知道的測試步驟，經使用者同意寫進測試子單；其餘由 RD 用 `rd-ticket-create` 補。規則見 `reference/subtask-patterns.md` 的「子任務的內文寫什麼」。
   - 例外：RD 自己做、不需要 QA 驗收的單（`RD_SELF`，由 `rd-ticket-create` 開）沒有測試人員，不寫「預期結果」。
     改成選填的「驗收方式」，寫一兩句怎樣算做完；位置照原本的預期結果（C 類寫在每一項需求下方，其他類寫在預期結果章節並改名「驗收方式」）。沒寫不算擋下，也不觸發需求完整性逼問。
10. 欄位標 `placeholder_allowed` 時，填那個指定的 `??` 字串不算擋下，但預覽要標 ⚠（例如示意圖還沒做時的 `?? 待補示意圖`）。
11. 中等決定論：core 欄位（TG、商戶、Callback、IP、費率）必須走 manifest schema；auxiliary 欄位（注意事項、附件說明、自由補充）AI 可自行構句但必須掛在指定章節下。

## Placeholder 安全規則（紅線，違反 abort）

| 紅線 | 錯誤示範 | 正確做法 |
|---|---|---|
| `??` 不可包進 markdown link 語法 | `[群名](?? 待提供連結)` | `群名（?? 待提供連結）` |
| `??` 不可包進 image 語法 | `![image](?? 待上傳)` | `?? 待上傳：filename.png` |
| Secret/Token/Password 預設不接受裸寫 | `ApiKey: [REDACTED] | `ApiKey: ?? 由 PM 私訊提供`；除非使用者明確確認「這是測試環境用的值，或已經遮蔽過的值」 |
| 收摺區（圖片、長對話、xmind）強制 `<details><summary>...</summary>...</details>` | 直接貼 1000 字對話 | 包進 details |

## Manifest YAML 格式

```yaml
schema_id: A-payment   # 對應檔名前綴
category: A
sections:
  - id: tg_group
    section_header: null   # null 表示無 ## 標題（首行 TG）
    fields:
      - { name: TG_NAME, required: true, type: text }
      - { name: TG_URL,  required: true, type: url, regex: "^https?://t\\.me/" }
  - id: currency_rate
    section_header: "##  幣別與匯率"
    fields:
      - { name: CURRENCY, required: true, type: enum, values: [MYR, USD, USDT, IDR, ...] }
      - { name: RATE,     required: true, type: text, hint: "1:1 / 1:1000" }
  ...
```

## Skill 載入流程

1. 載 `<schema>.md` → 解析 front-matter manifest + 骨架 body
2. 將 routing answers 已知欄位 (站台、廠商名等) 自動填入
3. **逐欄位提示使用者補值**（按 manifest 順序，只問必填欄位；選填段落不主動問，見規則 6 與 SKILL.md 的欄位填寫順序）：
   - text/url → 直接讓使用者貼
   - enum → 用 AskUserQuestion 列選項
   - secret → 預設問「這個欄位要先放待補文字、之後再私訊提供，還是現在就貼真的值？」
4. 收集完成後產預覽：照骨架的中文段落標題逐段列出狀態 ✓/✗/⚠，不印欄位代號
5. 任何必填欄位 ✗ → 擋下，使用者必須補值或改成待補文字才能繼續

## 收摺區 (collapsed) 統一寫法

```markdown
<details><summary>📎 附件 / 對話紀錄</summary>

<!-- 圖片 / xmind / 對話紀錄 -->

</details>
```
