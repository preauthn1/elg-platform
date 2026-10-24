<?php

declare(strict_types=1);

namespace App\Enums;

enum BetRecordRecoveryTypeEnum: string
{
    /** 補帳：把原本應該由 settle callback 完成的更新人工補上 */
    case RECOVER = 'recover';

    /** 撤回補帳：將先前的 recover 還原 */
    case REVERT = 'revert';

    /**
     * 退款：下注已扣款但三方判定投注失敗、永不派彩（如 2026-09-05 TPower DDoS 的 FumaSW timeout 單），
     * 由我方把下注金額退回錢包並將注單標為 BetRecordStatusEnum::REFUND。
     * 與 recover／revert 無父子關係（parent_log_id 恆為 NULL）；同一注單最多一次 refund，由命令以本表判定冪等。
     */
    case REFUND = 'refund';

    /**
     * 投注量歸零：注單已由三方退款（取消投注）、錢也已退回玩家，但注單與 total_bet_records
     * 還留著原始投注額，於是被 SR11／SR12、三層代理返利與提款流水門檻當成有效投注。
     * 本事件把注單與 TBR 的投注量欄位歸零，不動錢包（balance_diff 恆為 0），
     * 與 recover／revert 無父子關係（parent_log_id 恆為 NULL）；同一注單最多一次，由命令以本表判定冪等。
     */
    case ZERO_TURNOVER = 'zero_turnover';
}
