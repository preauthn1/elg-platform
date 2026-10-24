# 好測試與壞測試（PHP / PHPUnit）

範例用 Laravel 專案的 PHPUnit 寫法，情境是訂單與錢包。重點在測試的形狀，不在這些類別本身。

## 好測試

**整合風格**：透過真正的介面測，不去 mock 內部零件。

```php
// 好：驗的是從外面觀察得到的行為
public function test_會員可以用有效的購物車結帳(): void
{
    $cart = $this->cartService->create($this->member);
    $this->cartService->add($cart, $this->product, quantity: 2);

    $order = $this->checkoutService->place($cart, PaymentMethod::CreditCard);

    $this->assertSame(OrderStatus::Confirmed, $order->status);
}
```

特徵：

- 測的是呼叫者真正在乎的行為
- 只用公開介面
- 內部重構之後還是會過
- 描述的是「做得到什麼」，不是「怎麼做到的」
- 一支測試一個邏輯上的斷言

## 壞測試

### 跟實作綁死

```php
// 壞：驗的是內部結構
public function test_結帳會呼叫_PaymentService_的_charge(): void
{
    $payment = $this->mock(PaymentService::class);
    $payment->shouldReceive('charge')
        ->once()
        ->with($this->cart->total());

    $this->checkoutService->place($this->cart, PaymentMethod::CreditCard);
}
```

警訊：

- mock 了內部協作者（`PaymentService` 是自己家的類別）
- 測私有方法
- 斷言呼叫次數或呼叫順序
- 行為沒變、只是重構，測試就壞了
- 測試名稱寫的是「怎麼做」而不是「做得到什麼」
- 不經介面、用其他手段驗證

測私有方法也屬於這一類：

```php
// 壞：用反射撬開私有方法來測
public function test_calculateDiscount_會打九折(): void
{
    $method = new ReflectionMethod(CheckoutService::class, 'calculateDiscount');
    $method->setAccessible(true);

    $this->assertSame(900, $method->invoke($this->checkoutService, 1000));
}
```

私有方法不是縫。要測折扣規則，就從會用到它的公開介面（例如 `place()` 回傳的訂單金額）去驗；如果從外面驗不到，代表這段邏輯值得被抽成自己的公開介面，而不是該用反射硬測。

### 繞過介面去驗證

```php
// 壞：不用讀取介面，直接查資料庫確認
public function test_建立會員會寫進資料庫(): void
{
    $this->memberService->create(['name' => 'Alice']);

    $this->assertDatabaseHas('members', ['name' => 'Alice']);
}

// 好：從介面驗證
public function test_建立會員之後查得到這個會員(): void
{
    $member = $this->memberService->create(['name' => 'Alice']);

    $this->assertSame('Alice', $this->memberService->find($member->id)->name);
}
```

差別在於：前者把資料表欄位當成契約，換掉儲存方式就壞；後者把「建完之後查得到」當成契約，那才是呼叫者在乎的事。

### 自我證明

預期值用跟實作同一套算法算出來，所以測試從一開始就注定會過。

```php
// 壞：預期值是用實作的同一套算法算出來的
public function test_total_會把明細加總(): void
{
    $items = [['price' => 10], ['price' => 5]];
    $expected = array_sum(array_column($items, 'price'));

    $this->assertSame($expected, $this->calculator->total($items));
}

// 好：預期值是獨立的已知字面值
public function test_total_會把明細加總(): void
{
    $this->assertSame(15, $this->calculator->total([['price' => 10], ['price' => 5]]));
}
```

同樣的問題也會出現在錢包這種帶換算的情境：要驗「儲值 1000、手續費 2% 之後入帳多少」，就直接寫 `980`，不要在測試裡再跑一次 `1000 * (1 - 0.02)`。
