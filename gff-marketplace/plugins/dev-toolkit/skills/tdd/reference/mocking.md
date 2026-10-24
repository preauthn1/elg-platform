# 什麼時候可以用替身（Laravel / PHPUnit）

替身（mock、fake、stub：測試時用來頂替真實物件的假物件）**只用在系統邊界**。

## 可以用替身的地方

- 外部 API（金流商、遊戲商、寄信、簡訊）
- 資料庫（有時候；優先用測試資料庫跑真的）
- 時間與隨機
- 檔案系統（有時候）

## 不要用替身的地方

- 自己寫的類別
- 內部協作者
- 任何你自己控制得了的東西

把自己家的 service 換成 mock，測試就跟實作綁死了，重構必壞（見 [good-and-bad-tests.md](good-and-bad-tests.md)）。

## Laravel 的做法

### 外部 HTTP 用 `Http::fake()`

```php
public function test_代收下單失敗時訂單標記為失敗(): void
{
    Http::fake([
        'example.invalid/*' => Http::response(['status' => 'rejected'], 200),
    ]);

    $order = $this->depositService->create($this->member, amount: 1000);

    $this->assertSame(DepositStatus::Failed, $order->status);
}
```

### 邊界介面用 service container 綁替身

外部系統在專案裡有一層自己的介面時，在 container 把它換成假實作，測試就不必真的打出去：

```php
// TestCase 內
$this->app->bind(PaymentGatewayContract::class, fn () => new FakePaymentGateway());
```

寫一個行為可控的 `FakePaymentGateway`（回成功、回失敗、回逾時），比用 mock 去斷言「有沒有被呼叫、被呼叫幾次」好：前者驗的是行為結果，後者驗的是呼叫細節。

### 佇列與事件用 `Queue::fake()` / `Event::fake()`

```php
Queue::fake();

$this->withdrawalService->submit($this->member, amount: 500);

Queue::assertPushed(ReviewWithdrawal::class);
```

注意：`Event::fake()` 之後 listener 不會真的執行。只有在「有沒有派發這個事件」本身就是你要驗的邊界時才用；如果你要驗的是 listener 做完事之後的結果，就不要 fake。

### 時間用 `travelTo` / `travel` / `freezeTime`

```php
$this->travelTo(Carbon::parse('2026-01-01 00:00:00'));

$order = $this->depositService->create($this->member, amount: 1000);

$this->travel(31)->minutes();

$this->assertTrue($this->depositService->isExpired($order));
```

不要為了測時間，在自己的 service 裡多開一個「現在時間」參數硬塞；Laravel 的時間旅行已經涵蓋這件事。

### 檔案系統用 `Storage::fake()`

```php
Storage::fake('reports');
```

### 資料庫優先跑真的

能用測試資料庫就用（搭配 `RefreshDatabase`），不要 mock repository 或 query builder。mock 掉資料庫等於把 SQL、關聯、約束全部排除在測試之外，那通常正是會出事的地方。
