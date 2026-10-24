<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

class EventSummaryData extends Data
{
    public function __construct(
        public int $id,
        public int $auto_payout,
        public int $event_detail_id,
        public int $event_bind_unlimited,
        public int $ip_block,
        public int $global_ip_block,
        public int $phone_block,
        public int $global_phone_block,
        public int $account_block,
        public int $global_account_block,
        public int $realname_block,
        public int $tgid_block,
        public int $prize_id,
        public int $prize_content_id,
        public string $prize_content_name,
        public int $prize_content_number1,
        public int $prize_content_sent,
        public int $prize_content_point
    ) {}
}
