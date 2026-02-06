<?php

namespace App\Enums;

enum MessageType: string
{
    case Reply = 'reply';
    case InternalNote = 'internal_note';
}
