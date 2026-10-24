<?php

declare(strict_types=1);

namespace App\Vendors\Contracts;

use App\Data\BaseTotalBetRecordData;
use App\Data\InsertedBetRecordData;

/**
 * 遊戲商「拉注單」能力契約
 *
 * 單一錢包（is_single_wallet）實作本契約只為了三方 ↔ DB 比對：注單已由回調即時記帳，
 * BetRecordFetchService 對其只放行乾跑（--dry-run），寫入模式一律拒絕。
 *
 * 定義 BetRecordFetchService 拉單所需的全部方法；實作本介面即可被
 * fetch_bet_record --direct --vendor 手動觸發補拉，要加入自動排程
 * 請實作 ShouldScheduleBetRecordFetch（繼承本契約的純 marker）。
 *
 * 🔴 **禁止在 Driver 內直接寫 BR 或 TBR**，也禁止另組一套逐商排程：本契約的方法一律只回傳
 * 資料，寫入與去重（betRecordUniqueKey()）、TBR 轉檔同交易、逐商錯誤隔離全由
 * BetRecordFetchService 負責。拉取失敗一律拋例外交呼叫端處理，禁止 Driver 內吞例外或自行重送。
 *
 * @knowledge rules/bet-record-pull
 */
interface SupportsBetRecordFetch
{
    /**
     * 載入商戶連線參數（API_URL／憑證／PROVIDER 等）供 fetchBetRecords、formatBetRecord 使用
     *
     * 與錢包操作同慣例：BetRecordFetchService 對每組憑證先呼叫本方法，再呼叫其餘拉單方法，
     * driver 不需（也不應）自行在拉單方法內補呼叫；formatBetRecord 可直接依賴載入後的商戶狀態。
     *
     * @param  array<string, mixed>  $info  vendorInfo->info（API 連線參數）
     */
    public function setInfo(array $info): void;

    /**
     * 憑證識別鍵：同鍵的 vendor_info 共用同一組三方憑證，同一時間片只拉一次；
     * 回空字串表示無法識別，退回以 vendor_info id 各自拉取
     *
     * @param  array<string, mixed>  $info  vendorInfo->info（API 連線參數）
     */
    public function betRecordCredentialKey(array $info): string;

    /**
     * 三方 API 單次查詢的最大時間窗口（分鐘）
     * 超出時 BetRecordFetchService 會自動切片
     */
    public function betRecordApiWindowMinutes(): int;

    /**
     * 可拉取注單的最早時間（UTC datetime 字串），null 表示不限制
     *
     * 窗口早於此界線的部分由 BetRecordFetchService 收斂起點；
     * 整段窗口皆早於界線則不執行任何動作
     */
    public function betRecordMinStartTime(): ?string;

    /**
     * 三方拉單 API 的頻率限制（跨 vendor_info 共用同一預算）
     *
     * @return array{max_calls: int, per_seconds: int}
     */
    public function betRecordApiRateLimit(): array;

    /**
     * 拉取已結算注單（連線參數取自 setInfo 載入的商戶狀態）
     *
     * 負責呼叫三方 API 並回傳原始注單陣列；空陣列 = 該時段無注單。
     * 拉取失敗（HTTP 錯誤、三方回錯誤碼）直接拋例外，由呼叫端處理
     * （排程 Job 記 failed_jobs、--direct / --dry-run 逐商隔離回報）。
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \Throwable
     */
    public function fetchBetRecords(string $fromDate, string $toDate): array;

    /**
     * 將原始三方注單轉為「{tname}_bet_records 實際欄位」的陣列
     *
     * 必須包含 betRecordUniqueKey() 指定的唯一鍵欄位；
     * 寫入預設採唯一鍵去重後純新增；--update 模式下金額有異動的既有列
     * 會被更新並重轉 TBR（取消⇄正常互轉靠金額變化生效）。
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function formatBetRecord(array $raw, string $rate): array;

    /**
     * 注單表的唯一鍵欄位名稱（寫入前去重用），例如 referenceID
     */
    public function betRecordUniqueKey(): string;

    /**
     * 帳號表對應注單玩家的欄位名（member 解析用），例如 player_id
     *
     * formatBaseTotalBetRecord() 回傳的 accountKey 值以此欄位比對 getAccountModelClass() 的帳號表
     */
    public function betRecordAccountColumn(): string;

    /**
     * 將拉單新增列轉為 total_bet_records 基礎欄位 DTO
     *
     * 不需轉入者（如非投注／彩金交易、未完成單）回傳 null；
     * member_id / game_id / valid_bet_rate / summary_time 由 BetRecordFetchService 解析補齊
     */
    public function formatBaseTotalBetRecord(InsertedBetRecordData $record): ?BaseTotalBetRecordData;

    /** BetRecord Model 類別名稱，例如 Ace333BetRecord::class */
    public function getBetRecordModelClass(): string;

    /** Account Model 類別名稱，例如 Ace333Account::class */
    public function getAccountModelClass(): string;
}
