# 開單後到 Slack 通知開發提測

一句話版本：**主單和子任務都建好、日期核對完之後，到 Slack 對應頻道發一則三行通知，@ 負責開發的人，請他在開發子任務的結束日下班前提測。發送前先給使用者確認。**

## 名詞速查

| 名詞 | 意思 |
|---|---|
| 提測 | 開發做完，交給測試人員驗收 |
| 站別標記 | 標題裡 `[GFF]` 後面的方括號，例如 `[TP]`，代表這張單是哪個站點的 |
| @ 提及 | 在 Slack 訊息裡標記某人，對方會收到通知 |

---

## 發到哪個頻道

看主單標題的站別標記，對照 `title-format.md` 的「站點縮寫與 Slack 頻道」表。只有 `[GFF]` 的共同區塊貼 `#gff-general`。表上沒有的站先問使用者，不要猜。

### 舊單怎麼判斷頻道

舊單的站別標記寫法比較雜，依標題裡出現的字判斷，比對不分大小寫：

| 標題裡出現 | 頻道 |
|---|---|
| `vwsg`、`vworldsg` | `#gff-vworldsg` |
| `vw`、`vwmy`、`vworld` | `#gff-vworld` |
| `tp`、`tpower` | `#gff-tpower站` |
| `wtt`、`wintoto` | `#gff-wintoto` |
| `y2w`、`yes2win` | `#gff-yes2win站` |
| `ttn` | `#ttn-工作群` |
| 只有 `[GFF]` | `#gff-general` |

`vwsg` 要排在 `vw` 前面先比，不然 VWorldSG 的單會被當成 VWorld。

---

## 訊息格式

一張主單發一則；一次開多張就分別發。同一張單有好幾位開發，就在第三行放好幾個 @。

```
http://example.invalid:1688/target/tickets/-/work_items/<主單號>
<功能名稱>
@<開發的 Slack 帳號> 請協助於<開發子任務結束日>下班前提測，感謝
```

- **功能名稱**：主單標題拿掉 `[GFF]`、站別標記，以及尾端的表單項次。
- **日期**：開發子任務的結束日，寫成 `11/2`，月和日都不補零。開發子任務沒有結束日就不發，回報使用者。

範例（#4557）：

```
http://example.invalid:1688/target/tickets/-/work_items/4557
會員 Google 2FA 強化
@hongzeyan 請協助於10/22下班前提測，感謝
```

---

## Slack 帳號對照

Slack 帳號跟 GitLab 帳號不一定一樣，不能拿 GitLab 帳號直接當 Slack 帳號用。

| GitLab | Slack |
|---|---|
| hongze | hongzeyan |
| Max | max |
| daniel | Daniel |
| rb | RB |
| Tim | Tim |
| Rhys | Rhys |
| mars | Mars |
| toro | toro |
| neil | Neil |
| ryan | Ryan |

表上沒有的人，在 Slack 訊息框打 `@` 加名字，從下拉選單選到本人，再把帳號補進這張表。

---

## 怎麼發

Slack 沒有串接工具，用使用者的瀏覽器（Claude in Chrome）操作。

1. 先把擬好的訊息全部列給使用者看，使用者回 y 才發。
2. 打開 Slack，點左側的頻道，**確認頻道標題已經換成目標頻道**。點側欄有時沒切過去，曾經停在別的頻道。
3. 點訊息框，打網址，按 `shift+Return` 換行；打功能名稱，再按 `shift+Return`。
4. 打 `@名字`，等下拉選單出現，**看清楚第一列是本人**再按 `Return` 選取。打 `@max`、`@mars`、`@rb` 時，下拉會同時出現 Mars、MarshallChen、Rhys 這類名字相近的人。
5. 打「請協助於…下班前提測，感謝」。
6. 放大看一眼訊息框：網址、功能名稱、@ 都對，才按 `Return` 送出。
7. 全部發完，讀回頻道確認每一則都在。

注意：

- **@ 一定要從下拉選單選人**。直接打出 `@xxx` 的純文字不會通知到對方。
- 中文直接輸入不會掉字；萬一掉字，改用剪貼簿貼上。
- 只在通知頻道發這一則，不在請假頻道或其他頻道發。
