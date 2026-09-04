<?php

namespace App\Enums;

enum AttachmentScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Rejected = 'rejected';
}
