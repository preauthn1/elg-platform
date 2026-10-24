<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\CCPayment;

/**
 * CCPayment 永久地址的 referenceId：`{agent_cash_flow_order_type_id}-{member_id}`。
 *
 * 與 member_crypto_wallets 的鍵（支付通道＋會員）同鍵，回調帶回 referenceId 時
 * 可直接反解出支付通道與會員，再與該表核對；第二條支付通道或第二條鏈的 id 不同，會自然拿到不同地址。
 *
 * 金流商限制 3 到 64 字元（api.md 永久地址 API 一節）：兩個正整數最短 `1-1` 三字元、最長 39 字元，
 * 恆在範圍內。字元集規格未載、官方範例都是純數字；連字號已用 Dev 的測試商戶實打確認可取址（runbook.md 閘門一節）。
 * 若之後被拒，改純數字固定寬度，只要改這個類別。
 *
 * 多鏈不另做預留：之後加鏈就是加一條支付通道，referenceId 含通道 id，各通道各自取址。唯一要注意的是
 * EVM 相容鏈：金流商規則是一條 EVM 鏈的地址解綁時，其他 EVM 鏈上對應的地址也會一併解綁（api.md 解綁一節）。
 * 目前只接 TRON 不受影響；接第二條 EVM 鏈之前，要先處理風險地址解綁會連帶停掉同一會員其他 EVM 通道地址的情形。
 */
final class ReferenceId
{
    private const SEPARATOR = '-';

    public static function build(int $channelId, int $memberId): string
    {
        return $channelId . self::SEPARATOR . $memberId;
    }

    /**
     * 反解回調帶回的 referenceId；不是我方格式（含 0、負數、多段、前後空白）一律回 null。
     *
     * @return array{channel_id: int, member_id: int}|null
     */
    public static function parse(string $value): ?array
    {
        if (preg_match('/^([1-9]\d*)-([1-9]\d*)$/', $value, $matches) !== 1) {
            return null;
        }

        return ['channel_id' => (int) $matches[1], 'member_id' => (int) $matches[2]];
    }
}
