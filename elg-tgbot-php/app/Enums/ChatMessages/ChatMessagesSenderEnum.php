<?php

namespace App\Enums\ChatMessages;

enum ChatMessagesSenderEnum: string
{
    case SERVER = 'server';
    case USER = 'user';
}
