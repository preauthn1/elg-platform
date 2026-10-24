<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Helpers\Common;
use Illuminate\Database\Eloquent\Model;

/**
 * 各家 xxx_bet_records 的欄位宣告契約：欄位名由子類宣告，取值統一走本類的 getter。
 * 未宣告的欄位沿用 total_bet_records 的命名。
 *
 * 🔴 **取注單金額一律呼叫 getBet()／getValidBet()／getWinLose()／getWin()／
 * getRollingChip()／getJackpot()／getBonus()，禁止在服務、命令或報表裡硬寫某家的欄位名。**
 * 對帳與彙總 SQL 呼叫不到 PHP getter，必須改讀子類宣告的欄位名組出 mapping
 * （例如以 $betColumn 拼 SUM），一樣不得憑印象寫死字面欄位名。
 *
 * 基底刻意不給金額欄位預設值——欄位名各家不同，猜錯會算錯帳；未宣告即為 null，
 * 語意是「該表沒有單一欄位可 SUM」，**不得自行補一個看起來像的欄位**。
 * 宣告與實際 schema 的一致性由 {@see \Tests\Unit\Vendors\Models\BetRecordColumnParityTest} 釘住。
 *
 * 本類只管單筆注單的欄位語意：跨商彙整見 {@see \App\Models\TotalBetRecord}，
 * 玩家帳號與注單的對應關係見 {@see AbstractAccount}。
 */
abstract class AbstractBetRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = null;

    protected ?string $betTimeColumn = null;

    protected ?string $vendorUsernameColumn = null;

    /**
     * 投注金額欄位名。基底不給預設值——欄位名各家不同，猜錯會算錯帳，
     * 一律由子類別明確宣告（有 parity 測試把關）。null 代表無單一欄位可 SUM。
     */
    protected ?string $betColumn = null;

    /**
     * 有效投注欄位名；null 代表表無此欄位，取值時沿用投注欄位（對齊 getValidBet()）
     */
    protected ?string $validBetColumn = null;

    /**
     * 輸贏金額欄位名；null 代表由多欄位計算得出（例如派彩 - 投注），無單一欄位可 SUM
     */
    protected ?string $winLoseColumn = null;

    /**
     * 贏分（派彩）欄位名；null 代表表上沒有，改由輸贏 + 投注推得
     */
    protected ?string $winColumn = null;

    /**
     * 禮碼欄位名（各家為 rolling_chip 或 rolling_chips）；null 代表表上沒有
     */
    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    /**
     * 注單唯一識別碼欄位名；null 代表表上沒有可用的識別碼
     */
    protected ?string $betIdColumn = null;

    /**
     * 贏分（派彩）。表上沒有 win 欄位時，以輸贏 + 投注推回。
     */
    public function getWin(): string
    {
        if ($this->winColumn !== null) {
            return (string) ($this->{$this->winColumn} ?? '0');
        }

        return Common::numAdd($this->getWinLose(), $this->getBet(), 4);
    }

    public function getBet(): string
    {
        return $this->betColumn === null ? '0' : (string) ($this->{$this->betColumn} ?? '0');
    }

    /**
     * 有效投注；表上沒有專屬欄位時等同投注
     */
    public function getValidBet(): string
    {
        $column = $this->getValidBetColumn();

        return $column === null ? '0' : (string) ($this->{$column} ?? '0');
    }

    /**
     * 輸贏。表上沒有 win_lose 欄位時，以派彩 - 投注推得（例如 Scr、Ace333、Ct）。
     */
    public function getWinLose(): string
    {
        if ($this->winLoseColumn !== null) {
            return (string) ($this->{$this->winLoseColumn} ?? '0');
        }

        if ($this->winColumn === null) {
            return '0';
        }

        return Common::numSub((string) ($this->{$this->winColumn} ?? '0'), $this->getBet(), 4);
    }

    public function getRollingChip(): string
    {
        return $this->rollingChipColumn === null ? '0' : (string) ($this->{$this->rollingChipColumn} ?? '0');
    }

    public function getJackpot(): string
    {
        return $this->jackpotColumn === null ? '0' : (string) ($this->{$this->jackpotColumn} ?? '0');
    }

    public function getBonus(): string
    {
        return $this->bonusColumn === null ? '0' : (string) ($this->{$this->bonusColumn} ?? '0');
    }

    public function getBetId(): ?string
    {
        return $this->betIdColumn === null ? null : (string) $this->{$this->betIdColumn};
    }

    public function setRollingChip(string $rollingChip): void
    {
        if ($this->rollingChipColumn !== null) {
            $this->{$this->rollingChipColumn} = $rollingChip;
        }
    }

    public function getBetColumn(): ?string
    {
        return $this->betColumn;
    }

    /**
     * 有效投注欄位名；未宣告（null）時退回投注欄位，對齊 getValidBet() 的 fallback
     */
    public function getValidBetColumn(): ?string
    {
        return $this->validBetColumn ?? $this->betColumn;
    }

    /**
     * 輸贏金額欄位名；回 null 代表由多欄位計算得出（對齊 getWinLose()）
     */
    public function getWinLoseColumn(): ?string
    {
        return $this->winLoseColumn;
    }

    /**
     * 贏分（派彩）欄位名；null 代表表上沒有，由輸贏 + 投注推得
     */
    public function getWinColumn(): ?string
    {
        return $this->winColumn;
    }

    public function getRollingChipColumn(): ?string
    {
        return $this->rollingChipColumn;
    }

    public function getJackpotColumn(): ?string
    {
        return $this->jackpotColumn;
    }

    public function getBonusColumn(): ?string
    {
        return $this->bonusColumn;
    }

    public function getBetIdColumn(): ?string
    {
        return $this->betIdColumn;
    }

    public function getPayoutTimeColumn(): ?string
    {
        return $this->payoutTimeColumn;
    }

    public function getBetTimeColumn(): ?string
    {
        return $this->betTimeColumn;
    }

    public function getVendorUsernameColumn(): ?string
    {
        return $this->vendorUsernameColumn;
    }
}
