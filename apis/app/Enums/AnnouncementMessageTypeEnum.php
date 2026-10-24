<?php

declare(strict_types=1);

namespace App\Enums;

enum AnnouncementMessageTypeEnum: int
{
    // 針對chat_group
    case CHAT_GROUP = 1;

    // 針對代理樹狀
    case AGENT_TREE = 2;

    // 針對單一代理
    case AGENT = 3;

    // 針對特定Member
    case MEMBER = 4;

    // 針對組織
    case ORGANIZATION = 99;
}
