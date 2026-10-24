<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\ThreeHierarchy;

use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 三層代派發紀錄——下鑽明細列 工單4199
 *
 * 一列＝ three_hierarchy_bonus_report 的一列（來源會員 × 遊戲商 × 遊戲類別 × 反水類別），
 * 與前台會員版 achievements() 同粒度。比例由前端以 返利 ÷ 基數 反算。
 */
class PayoutRecordDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = Common::numAdd(
            Common::numAdd((string) $this->bet, (string) $this->down_bet, 4),
            (string) $this->down2_bet,
            4,
        );
        $reward = Common::numAdd(
            Common::numAdd((string) $this->rebate, (string) $this->down_rebate, 4),
            (string) $this->down2_rebate,
            4,
        );

        return [
            /**
             * @var string|null 來源會員帳號（是誰的投注產生這筆返利）
             *
             * @example "KGL6947"
             */
            'from_username' => $this->fromMember?->username,
            /**
             * @var string|null 層級：self=自身、down=下層、down2=下下層；金額全 0 無法判定時為 null（前端顯示「—」）
             *
             * @example "down"
             */
            'tier' => $this->resolveTier(),
            /**
             * @var string|null 遊戲商
             *
             * @example "918KISS"
             */
            'vendor_name' => $this->vendor?->name,
            /**
             * @var string|null 遊戲類別
             *
             * @example "SLOTS"
             */
            'game_type_name' => $this->gameType?->name,
            /**
             * @var int 反水類別：1=投注量、2=輸分（防呆「混合」時前端顯示此欄與分類小計）
             *
             * @example 1
             */
            'rebate_type' => (int) $this->rebate_type,
            /**
             * @var string 基數（該列的投注額或輸分）
             *
             * @example "1271.3500"
             */
            'base' => $base,
            /**
             * @var string 返利
             *
             * @example "1.2714"
             */
            'reward' => $reward,
        ];
    }

    /** 層級由非零金額欄推斷；全 0 列（未達門檻仍寫列）無依據，回 null */
    private function resolveTier(): ?string
    {
        if (Common::numCompare((string) $this->bet, '0', 4) > 0 || Common::numCompare((string) $this->rebate, '0', 4) > 0) {
            return 'self';
        }
        if (Common::numCompare((string) $this->down_bet, '0', 4) > 0 || Common::numCompare((string) $this->down_rebate, '0', 4) > 0) {
            return 'down';
        }
        if (Common::numCompare((string) $this->down2_bet, '0', 4) > 0 || Common::numCompare((string) $this->down2_rebate, '0', 4) > 0) {
            return 'down2';
        }

        return null;
    }
}
