<?php

namespace App\Enums\ChatMessages;

enum ChatMessagesStatusEnum: string
{
    case DONE = 'done';
    case PENDING = 'pending';
    case FAILED = 'failed';
    case DROPPED = 'dropped';

}
