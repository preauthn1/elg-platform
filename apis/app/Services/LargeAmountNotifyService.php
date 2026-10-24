<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Agent\RoleEnum;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\TotalBetRecord;
use App\Models\Vendor;
use Illuminate\Support\Facades\Log;

/**
 * 大額異動通知（存款、提款、遊戲商轉入／轉出、大額投注、大額派彩）。
 *
 * 觸發條件恆為「金額 ≥ threshold」**且**「代理落在監控名單」兩者同時成立——
 * **禁止各業務點自寫門檻比較、自訂頻道或繞過 watch list**；新場景在本 Service 新增一支
 * notifyXxx()，優先複用 private guard()／send()（大額投注與大額派彩兩支因為要先取注單才判得動，
 * 是直接呼叫 Common::isAgentInLargeAmountWatchList，條件相同但沒走 guard()）。
 * 門檻與監控名單一律走 config/bot-notify.php
 * 的 large_amount.threshold 與 large_amount.watch_agents（env 覆寫），不寫死在代碼。
 *
 * 排查「為什麼沒通知」的順序：門檻（LARGE_AMOUNT_THRESHOLD，預設 5000）→ 該代理的
 * hierarchy_array 是否落在 LARGE_AMOUNT_WATCH_AGENTS → LARGE_AMOUNT_CHAT_ID 是否配置。
 *
 * 訊息送出委由 BotNotify 的 large_amount 頻道（見 {@see BotNotifyService}）；
 * 例外類告警不屬本 Service，走 {@see \App\Services\BotNotify\AlertGate}。
 */
class LargeAmountNotifyService
{
    private function guard(string $amount, array $hierarchyArray): bool
    {
        $threshold = config('bot-notify.large_amount.threshold');

        return Common::numCompare($amount, (string) $threshold) >= 0
            && Common::isAgentInLargeAmountWatchList($hierarchyArray);
    }

    /**
     * 以 sendOnCommit() 送出：交易 rollBack 代表這筆金流沒發生，通知就不該送出去
     */
    private function send(string $message, array $logContext): void
    {
        BotNotify::channel('large_amount')->sendOnCommit($message);
        Log::info('大額通知已發送', $logContext);
    }

    public function notifyDeposit(Member $member, Agent $agent, int|float|string $point, int|float|string $balance): void
    {
        $this->notifyMemberCashFlow($member, $agent, '存款', (string) $point, (string) $balance);
    }

    public function notifyWithdrawal(Member $member, Agent $agent, int|float|string $point, int|float|string $balance): void
    {
        $this->notifyMemberCashFlow($member, $agent, '提款', (string) $point, (string) $balance);
    }

    private function notifyMemberCashFlow(Member $member, Agent $agent, string $type, string $point, string $balance): void
    {
        if (!$this->guard($point, $agent->hierarchy_array)) {
            return;
        }

        $message = implode(PHP_EOL, [
            sprintf('【單次大額%s通知】', $type),
            ...$this->buildMemberIdentityLines($member, $agent),
            sprintf('%s金額：', $type) . $point,
            '目前餘額：' . $balance,
        ]);

        $this->send($message, [
            'username' => $member->username,
            'point' => $point,
            'balance' => $balance,
        ]);
    }

    public function notifyVendorTransferOut(Member $member, Vendor $vendor, string $transferAmount): void
    {
        $this->notifyVendorTransfer($member, $vendor, $transferAmount, '轉出');
    }

    public function notifyVendorTransferIn(Member $member, Vendor $vendor, string $transferAmount): void
    {
        $this->notifyVendorTransfer($member, $vendor, $transferAmount, '轉入');
    }

    private function notifyVendorTransfer(Member $member, Vendor $vendor, string $transferAmount, string $direction): void
    {
        if (!$this->guard($transferAmount, $member->agent->hierarchy_array)) {
            return;
        }

        $message = implode(PHP_EOL, [
            sprintf('【遊戲商大額%s通知】', $direction),
            ...$this->buildMemberIdentityLines($member, $member->agent),
            '遊戲商：' . $vendor->name,
            sprintf('%s金額：', $direction) . $transferAmount,
        ]);

        $this->send($message, [
            'username' => $member->username,
            'vendor' => $vendor->name,
            'transfer_amount' => $transferAmount,
        ]);
    }

    /**
     * 投注紀錄大額通知
     *
     * 內部會先做廉價的欄位門檻檢查，全部未超標時直接 return，避免高頻投注流程多打 DB；
     * 通過後才查詢 Member/Vendor/Game 並送出通知。
     */
    public function notifyLargeBetRecord(int $memberId, int $vendorId, TotalBetRecord $record, ?Game $game = null): void
    {
        $exceeded = $this->collectExceededFields($record, ['bet', 'valid_bet', 'rolling_chip']);
        if (empty($exceeded)) {
            return;
        }

        $member = Member::with('agent')->find($memberId);
        $vendor = Vendor::find($vendorId);
        if ($member === null || $vendor === null) {
            return;
        }

        if (!Common::isAgentInLargeAmountWatchList($member->agent->hierarchy_array)) {
            return;
        }

        $game ??= Game::find($record->game_id);

        $exceededLine = collect($exceeded)
            ->map(fn ($value, $field) => "$field=$value")
            ->implode(', ');

        $messageLines = $this->buildBaseMessageLines('【大額投注紀錄通知】', $member, $vendor, $game, $record->reference_no);
        $messageLines[] = '超標欄位：' . $exceededLine;

        $this->send(implode(PHP_EOL, $messageLines), [
            'username' => $member->username,
            'vendor' => $vendor->name,
            'reference_no' => $record->reference_no,
            'exceeded' => $exceeded,
        ]);
    }

    /**
     * 派彩大額通知（win_lose / bonus / jackpot）
     *
     * 與 notifyLargeBetRecord 各自獨立，由呼叫端明確觸發。
     */
    public function notifyLargePayout(int $memberId, int $vendorId, TotalBetRecord $record, ?Game $game = null): void
    {
        $exceeded = $this->collectExceededFields($record, ['win_lose', 'bonus', 'jackpot']);
        if (empty($exceeded)) {
            return;
        }

        $member = Member::with('agent')->find($memberId);
        $vendor = Vendor::find($vendorId);
        if ($member === null || $vendor === null) {
            return;
        }

        if (!Common::isAgentInLargeAmountWatchList($member->agent->hierarchy_array)) {
            return;
        }

        $game ??= Game::find($record->game_id);

        $exceededLine = collect($exceeded)
            ->map(fn ($value, $field) => "$field=$value")
            ->implode(', ');

        $messageLines = $this->buildBaseMessageLines('【大額派彩通知】', $member, $vendor, $game, $record->reference_no);
        $messageLines[] = '超標欄位：' . $exceededLine;

        $this->send(implode(PHP_EOL, $messageLines), [
            'username' => $member->username,
            'vendor' => $vendor->name,
            'reference_no' => $record->reference_no,
            'exceeded' => $exceeded,
        ]);
    }

    /**
     * @return list<string>
     */
    private function buildBaseMessageLines(string $title, Member $member, Vendor $vendor, ?Game $game, string $referenceNo): array
    {
        $lines = [
            $title,
            ...$this->buildMemberIdentityLines($member, $member->agent),
            '遊戲商：' . $vendor->name,
        ];
        if ($game !== null) {
            $lines[] = '遊戲：' . $game->name;
        }
        $lines[] = '交易號：' . $referenceNo;

        return $lines;
    }

    /**
     * 組玩家身份識別行：一級代理（總代直屬下層）＋會員帳號（含上級代理）。
     *
     * 上級代理 = members.parent（由呼叫端傳入，與 guard 用同一物件）；
     * 一級代理見 resolveFirstLevelAgentId，找不到時省略該行。
     *
     * @return list<string>
     */
    private function buildMemberIdentityLines(Member $member, Agent $superiorAgent): array
    {
        $firstLevelAgentId = $this->resolveFirstLevelAgentId($superiorAgent);

        $names = Agent::resolveUsernames([$firstLevelAgentId, $superiorAgent->id]);

        $lines = [];
        if ($firstLevelAgentId !== null && isset($names[$firstLevelAgentId])) {
            $lines[] = '一級代理：' . $names[$firstLevelAgentId];
        }
        $lines[] = sprintf(
            '會員帳號：%s (上級代理 %s)',
            $member->username,
            $names[$superiorAgent->id] ?? $superiorAgent->username,
        );

        return $lines;
    }

    /**
     * 取一級代理 id：hierarchy_path 中「最後一個總代（GENERAL_AGENT）的下一層」。
     *
     * VWorld 為多白標共用 DB，白標 root 與總代皆為 GENERAL_AGENT（兩層堆疊），
     * 故一級代理落在第四層；其餘單一白標 DB 僅一層總代，落在第三層。
     * 以「總代下一層」語意判定可同時涵蓋兩者，免硬編層數。
     * 階層中找不到總代、或總代已是最末層（無下級）時回傳 null。
     */
    private function resolveFirstLevelAgentId(Agent $agent): ?int
    {
        if ($agent->hierarchy_path === null) {
            return null;
        }

        $pathIds = array_values(array_filter(
            array_map('intval', explode('/', trim($agent->hierarchy_path, '/'))),
        ));
        if ($pathIds === []) {
            return null;
        }

        $roles = Agent::query()->whereIn('id', $pathIds)->pluck('role_id', 'id');

        $lastGeneralAgentIndex = null;
        foreach ($pathIds as $index => $id) {
            if ((int) ($roles[$id] ?? 0) === RoleEnum::GENERAL_AGENT->value) {
                $lastGeneralAgentIndex = $index;
            }
        }

        if ($lastGeneralAgentIndex === null) {
            return null;
        }

        return $pathIds[$lastGeneralAgentIndex + 1] ?? null;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function collectExceededFields(TotalBetRecord $record, array $fields): array
    {
        $threshold = (string) config('bot-notify.large_amount.threshold');

        $exceeded = [];
        foreach ($fields as $field) {
            if (Common::numCompare((string) $record->{$field}, $threshold) >= 0) {
                $exceeded[$field] = $record->{$field};
            }
        }

        return $exceeded;
    }
}
