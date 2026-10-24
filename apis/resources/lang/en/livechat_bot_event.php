<?php

declare(strict_types=1);

/*
 * V1 活動進度訊息範本。
 *
 * key 為 EventActionIdEnum 的 case name；佔位符：
 *   :current   目前點數
 *   :required  達標所需點數
 *   :remaining 尚需點數（required - current）
 */
return [
    'DEPOSIT' => 'Total deposit progress :current / :required, :remaining remaining to qualify.',
    'BET' => 'Total bet progress :current / :required, :remaining remaining to qualify.',
    'WIN' => 'Total win progress :current / :required, :remaining remaining to qualify.',
    'FIRST_DEPOSIT' => 'First deposit progress :current / :required.',
    'LOTTERY' => 'Lottery ticket progress :current / :required, :remaining remaining to qualify.',
    'SLOT_LOSE_REBATE' => 'Slot lose rebate progress :current / :required.',
    'JACKPOT' => 'Jackpot progress :current / :required.',
    'REG_BONUS' => 'Registration bonus progress :current / :required.',
    'LOGIN' => 'Login progress :current / :required, :remaining more login(s) required.',
    'BET_ONCE_A_DAY' => 'Daily bet progress :current / :required, :remaining more bet(s) required today.',
    'NEW_INVITE_PLAYER_OFFERS' => 'Invite new player progress :current / :required, :remaining more required.',
    'INVITE_PLAYER_DEPOSIT' => 'Invited player deposit progress :current / :required.',
    'INVITE_PLAYER_OFFERS' => 'Invite player progress :current / :required.',
    'PICK_UP_ONCE_A_DAY' => 'Daily pick-up progress :current / :required.',

    // Fallback when no specific message exists for an action_id
    'default' => 'Progress :current / :required, :remaining remaining to qualify.',
];
