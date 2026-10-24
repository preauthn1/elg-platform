<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\AnnouncementMessage;

use App\Http\Requests\AbstractRequest;

class ReadRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'announcement_message_id' => 'required|integer',
        ];
    }
}
