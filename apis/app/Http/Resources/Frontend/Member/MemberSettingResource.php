<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Member;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'activity_limit_time' => $this->activity_limit_time,
            'push_notify' => $this->push_notify,
            'sms_notify' => $this->sms_notify,
            'email_notify' => $this->email_notify,
            'message_box_notify' => $this->message_box_notify,
            'message_popup_notify' => $this->message_popup_notify,
        ];
    }
}
