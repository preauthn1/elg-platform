<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IpRestrictionBlockItemEnum;
use App\Models\IpRestriction;

/**
 * 風控IP限制 enforcement（風控管理 > IP限制，4096）。
 *
 * 消費點（一筆規則可多選阻擋項目，各消費點只認自己那一項）：
 *   - RegisterController（註冊當下擋下並累計 repeat_count）
 *   - LoginController::__invoke（登入當下擋下並累計 repeat_count，不依賴 member，
 *     連帳號不存在的嘗試也擋）
 *   - ValidationCodeController::handleSendCode（僅 verify_by=1 簡訊；email 驗證不受此項影響）
 *   - AuthMember middleware（每次前台授權請求即時檢查來源 IP，已在線者下一個請求
 *     被強制登出；踢線不累計，避免 repeat_count 灌水。只看「登入」項）
 *
 * 語意：
 *   - 只認 status=1（啟用）且該阻擋項目旗標=1 的規則；關閉即等同停用（本功能無 delete）。
 *   - 來源 IP 一律取 Common::getIp()（授權判斷專用），禁止 getAgentClientIp()——
 *     V-HTTP-CLIENT-IP 是呼叫端自填 header，拿來做授權等於把判斷權交給請求方。
 *   - IP 進出一律過 canonicalize()：IPv6 縮寫/大小寫變體（2001:DB8::1 vs 2001:db8::1）
 *     統一成 canonical form，寫入端（IpRestrictionService）與查詢端字串等值比對即可靠。
 *   - 查詢走 unique(ip) point lookup；request 生命週期內依「IP＋項目」memo，
 *     同一請求多次消費不重複查 DB。刻意不上 TTL cache——會造成後台啟用後踢不掉線的延遲。
 */
class IpRestrictionGuard
{
    /** @var array<string, bool> 單一 request 內的判定快取，key 為 "{ip}|{item}" */
    private array $blockedCache = [];

    /** 該來源 IP 是否被禁止進行該項目（middleware 踢線用，不寫 DB） */
    public function isBlocked(string $ip, IpRestrictionBlockItemEnum $item): bool
    {
        $ip = self::canonicalize($ip);

        return $this->blockedCache[$ip . '|' . $item->value] ??= IpRestriction::query()
            ->where('ip', $ip)
            ->where('status', 1)
            ->where($item->column(), 1)
            ->exists();
    }

    /**
     * 嘗試被擋時呼叫：單一 UPDATE 原子性完成「檢查＋累計 repeat_count」，回傳是否被擋。
     *
     * repeat_count 為該 IP 被擋下的嘗試總計，不分項目（後台單一欄位「重複次數」）。
     *
     * 必須 toBase()：Eloquent Builder 的 increment() 回傳 $this 而非 affected rows；
     * base builder 的 update 也不會動 updated_at/admin（migration 亦無 useCurrentOnUpdate），
     * 「更新者/更新時間」保留最後一次後台人工編輯的語意。
     */
    public function blockAttempt(string $ip, IpRestrictionBlockItemEnum $item): bool
    {
        $ip = self::canonicalize($ip);

        $affected = IpRestriction::query()
            ->where('ip', $ip)
            ->where('status', 1)
            ->where($item->column(), 1)
            ->toBase()
            ->increment('repeat_count');

        if ($affected > 0) {
            $this->blockedCache[$ip . '|' . $item->value] = true;
        }

        return $affected > 0;
    }

    /** IP canonical form（inet_pton→inet_ntop）；非法輸入原樣返回，交給比對自然不命中 */
    public static function canonicalize(string $ip): string
    {
        $packed = @inet_pton($ip);

        return $packed === false ? $ip : inet_ntop($packed);
    }
}
