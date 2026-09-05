<?php

namespace App\Enums;

enum TicketUpdateEvent: string
{
    case CustomerReply = 'customer_reply';
    case StaffReply = 'staff_reply';
    case AssignmentChanged = 'assignment_changed';
    case StatusChanged = 'status_changed';
    case Escalated = 'escalated';
}
