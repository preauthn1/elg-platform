<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 風控會員限制的目標型別（對外 API 用數字，DB 存 morph alias）。
 */
enum MemberRestrictionTargetEnum: int
{
    /** 会员管理（單一會員） */
    case MEMBER = 1;
    /** 会员群组（作用於群組內全體會員） */
    case MEMBER_GROUP = 2;

    /** DB target_type 存的 morph alias（見 AppServiceProvider 的 morphMap） */
    public function morphAlias(): string
    {
        return match ($this) {
            self::MEMBER => 'member',
            self::MEMBER_GROUP => 'member_group',
        };
    }

    public static function fromMorphAlias(string $alias): self
    {
        return match ($alias) {
            'member' => self::MEMBER,
            'member_group' => self::MEMBER_GROUP,
            default => throw new \ValueError("Unknown member restriction morph alias: {$alias}"),
        };
    }
}
