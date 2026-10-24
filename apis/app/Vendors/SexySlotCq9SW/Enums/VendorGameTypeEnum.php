<?php

declare(strict_types=1);

namespace App\Vendors\SexySlotCq9SW\Enums;

use App\Enums\Game\GameTypeEnum;

/**
 * CQ9 GameBoy game/list 回傳的 gametype 值
 */
enum VendorGameTypeEnum: string
{
    case SLOT = 'slot';
    case SEXY_SLOT = 'sexyslot';
    case VP_SLOT = 'vpslot';
    case FISH = 'fish';
    case SEXY_FISH = 'sexyfish';
    case VP_FISH = 'vpfish';
    case ARCADE = 'arcade';
    case SEXY_ARCADE = 'sexyarcade';
    case VP_ARCADE = 'vparcade';
    case TABLE = 'table';
    case VP_TABLE = 'vptable';
    case LIVE = 'live';
    case LIVE_GAME = 'livegame';
    case DEALER_GAME = 'dealergame';
    case LOTTO = 'lotto';
    case SPORTS = 'sports';
    case ANIMAL = 'animal';

    /**
     * 轉回（rollin）回調的有效投注是否改用 bet 欄位。
     *
     * 廠商文件：老虎機／街機／魚機的 validbet 固定送 0，有效投注必須直接取 bet；
     * 牌桌／真人才會給真實的 validbet。
     */
    public function usesBetAsValidBet(): bool
    {
        return in_array($this->toGameType(), [GameTypeEnum::SLOTS, GameTypeEnum::FISHING, GameTypeEnum::ARCADE], true);
    }

    public function toGameType(): GameTypeEnum
    {
        return match ($this) {
            self::SLOT, self::SEXY_SLOT, self::VP_SLOT => GameTypeEnum::SLOTS,
            self::FISH, self::SEXY_FISH, self::VP_FISH => GameTypeEnum::FISHING,
            self::ARCADE, self::SEXY_ARCADE, self::VP_ARCADE => GameTypeEnum::ARCADE,
            self::TABLE, self::VP_TABLE, self::LIVE, self::LIVE_GAME, self::DEALER_GAME => GameTypeEnum::LIVE_CASINO,
            self::LOTTO => GameTypeEnum::LOTTERY,
            self::SPORTS => GameTypeEnum::SPORTS,
            self::ANIMAL => GameTypeEnum::COKE_FIGHTING,
        };
    }
}
