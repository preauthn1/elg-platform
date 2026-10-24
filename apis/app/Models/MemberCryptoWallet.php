<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 會員加密貨幣收款地址（member_crypto_wallets）。
 *
 * 一列＝一位會員在一條支付通道（agent_cash_flow_order_type_id）上的一個收款地址。表由多條支付通道
 * 共用，兩種模式並存：地址池（FonPay，預建 member_id = 0 的列再撥給會員）與永久地址（CCPayment，
 * 會員開存款方式清單或點選取址時向金流商取址）。接入契約（改動前先讀）：
 *
 * - 唯一鍵是（agent_cash_flow_order_type_id, address），它同時是回調反查的鍵。
 * - 一位會員在一條支付通道只能有一列 status = 1；換發新地址時舊列改 status = 0，禁止刪除或覆寫
 *   address，因為金流商端解綁後轉進舊地址的款仍會入帳，反查必須找得到原主人。
 * - 反查（回調的收款地址 → 會員）以（通道, address）查，不看 status。
 * - address 存入前必須 trim；比對一律在 SQL 的 where 做，禁止 SQL 撈列後再 PHP 端嚴格比對
 *   （欄位是 utf8mb4_unicode_ci，大小寫不分、尾隨空白視同相等，兩段式比對會給出相反答案）。
 * - 查詢一律以本通道的 agent_cash_flow_order_type_id 限定，禁止只用 member_id 撈；測試要用
 *   另一條支付通道塞同樣的列做隔離斷言。
 * - balance 欄位全 app 零寫入，不使用；admin 由系統寫入時填 0。
 * - 向金流商取址是冪等的（同一會員同一通道回同一地址），寫表失敗重試即可，不會多占配額。
 * - 永久地址的列只代表「這個地址屬於誰」，不代表「現在該顯示哪個地址」：地址綁在金流商戶上（CCPayment 是
 *   APP_ID），商戶換了，表裡的啟用列就屬於舊商戶。要顯示給會員的地址一律先向目前商戶確認
 *   （MemberCryptoWalletService::walletsForChannels），對不上就把舊列改停用、寫入新列。
 * - 表結構沿用既有欄位，沒有 chain 欄位：鏈別由支付通道決定，不同鏈就是不同通道。欄位 collation 要不要
 *   改成區分大小寫、要不要加 chain 欄位，等接第二條鏈時再評估；只接 TRON 時沒有非改 schema 不可的理由。
 *
 * @property int $id 流水號
 * @property int $agent_cash_flow_order_type_id 支付通道編號（agent_cash_flow_order_types.id）
 * @property int $member_id 會員系統編號
 * @property string $address 錢包地址
 * @property int $status 狀態; 0:停用, 1:啟用
 * @property numeric $balance 餘額
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|MemberCryptoWallet newModelQuery()
 * @method static Builder<static>|MemberCryptoWallet newQuery()
 * @method static Builder<static>|MemberCryptoWallet query()
 *
 * @mixin \Eloquent
 */
class MemberCryptoWallet extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
