# 轉帳錢包（Transfer Wallet）

> 這份管轉帳錢包（玩家要先把餘額轉進遊戲商）的 Driver 要實作哪些方法、轉帳結果怎麼回傳。選型確定是轉帳錢包、開始寫 Driver 時讀。

## 必須實作的方法

```php
class Vendor extends AbstractTransferWallet
{
    /** 查詢玩家在遊戲商的餘額（未轉換匯率） */
    public function getBalance(): string { ... }

    /** 取得遊戲啟動網址 */
    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData { ... }

    /** 將平台餘額轉入遊戲商 */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum { ... }

    /** 將遊戲商餘額轉回平台 */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum { ... }
}
```

## 轉帳結果回傳規則

**重要**：錯誤的回傳值會導致餘額異常：

| 情況 | 回傳值 |
|------|--------|
| 成功 | `VendorTransferResultEnum::SUCCESS` |
| 明確失敗 | `VendorTransferResultEnum::FAILED` |
| 無法確認（逾時、空響應、狀態碼不明） | `VendorTransferResultEnum::TIMEOUT` |

`TIMEOUT` 會觸發系統重試／人工確認流程，不確定時**必須**回傳 `TIMEOUT`。

## 可選覆寫的方法

```php
/** 確認轉帳狀態（當 transferToVendor/transferToLocal 回傳 TIMEOUT 時觸發） */
public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
{
    $result = $this->queryOrder($transactionNo);
    return match($result['status']) {
        'success' => VendorTransferResultEnum::SUCCESS,
        'failed'  => VendorTransferResultEnum::FAILED,
        default   => VendorTransferResultEnum::TIMEOUT,
    };
}
```
