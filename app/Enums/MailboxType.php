<?php

namespace App\Enums;

enum MailboxType: string
{
    case Imap = 'imap';
    case Gmail = 'gmail';
    case Microsoft = 'microsoft';
}
