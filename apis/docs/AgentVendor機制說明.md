# AgentVendor 機制

## 重構後機制

重構後的主要實作在 `VendorService::getVendorIds` `VendorService::getVendorInfoIds`

由下層 agent id 往上找，取各 vendor_id 在該代理鏈上最下層 agent_id 的 agent_vendor

換句話說，各 vendor_id 如果該層 agent_vendor 沒有設定就會拿父親設定的，一路到總代，**但會跳過 admin**

例：取全部

agent: id = 1113, hierarchy_path = /1/1116/1113 (id 大小不代表繼承順序)

agent_vendors:

| id  | vendor_id | agent_id |
| --- | --- | --- |
| 1   | 102 | 1113 |
| 2   | 105 | 1116 |
| 3   | 105 | 1113 |
| 4   | 106 | 1116 |

結果：

| id  | vendor_id | agent_id |
| --- | --- | --- |
| 1   | 102 | 1113 |
| 3   | 105 | 1113 |
| 4   | 106 | 1116 |

id = 2 因為有更低等級 agent 已經設定，所以就停在那裏

#### 更新 agent_vendors:

應該要在 **下層設定要跟上層不一樣時**，再 insert agent_vendors

1. 上層關 -> 下層所有對應 agent_vendor 都要關
  
2. 上層開 -> 下層不動作
  
3. 下層關 -> 假如上層是開著的，那要 insert agent_vendors，代表該 vendor 的設定不繼承上級
  
4. 下層開 -> 不影響
  

後臺可能需要新功能能夠刪除該 agent 特定 vendor 的設定？

#### 獨立開關 (self_status)

只有在 status = 0 時才有作用，代表雖然被停用，但**該 agent 的直屬會員要能夠玩**，而下層的所有會員依舊不能

---

## 重構前機制

原本的機制在各專案行為不一致，且現行邏輯導致新遊戲商不會自動出現在遊戲商列表中，要各層手動更新

API_Frontend：由下層往上找，遇到第一個有設定 agent_vendors 的 agent 就停下來，並只拿該層

例：

| id  | vendor_id | agent_id |
| --- | --- | --- |
| 1   | 102 | 1113 |
| 2   | 105 | 1116 |
| 3   | 105 | 1113 |
| 4   | 106 | 1116 |

結果：

| id  | vendor_id | agent_id |
| --- | --- | --- |
| 1   | 102 | 1113 |
| 3   | 105 | 1113 |

假如 vendor_id = 106 是新遊戲商，為了讓 1113 也能玩就得 insert new row into agent_vendors，最嚴重就是總代以下所有 agent 對應的 agent_vendors 都得 insert

API_Agent：代理遊戲商設置 (agentVendor/setting)，該頁面現在基本損毀沒有人在用

理論上：更新時指定 agent_id + vendor_id，但該 row 不存在時，**會由下層往上找**，直到找到第一個有設定的上層，把設定複製下來並更新該 vendor_id 的狀態

實際上：

1. 搜尋沒有指定 vendor_id，所以上層也沒有設定的話就會變成：下層有該 vendor_id 的設定，但其實上層沒有
  
2. 搜尋的起始點是自己，自己沒有才往上找，所以呈上則可知沒有指定 vendor_id，所以有可能會複製一份自己的 **(agent_vendors 沒有任何 unique key)**
  
3. 因為是後台系統所以可能可以不用那麼在意，新增時是 foreach 所有拿到的 agent_vendors 並一個一個 create，N+1嚴重