<?php

namespace App\Enums\ChatMessages;

enum ChatMessagesTypeEnum: string
{
    case TEXT = 'text';
    case IMAGE = 'image';
    case ANIMATION = 'animation';
    case AUDIO = 'audio';
    case VOICE = 'voice';

    case REQUEST_EDIT = 'request_edit';
    case REQUEST_DELETE = 'request_delete';

}
