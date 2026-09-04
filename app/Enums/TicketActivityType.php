<?php

namespace App\Enums;

enum TicketActivityType: string
{
    case TicketCreated = 'ticket.created';
    case AssignmentChanged = 'assignment.changed';
    case StatusChanged = 'status.changed';
    case PriorityChanged = 'priority.changed';
    case DepartmentChanged = 'department.changed';
    case TagsChanged = 'tags.changed';
    case WatcherAdded = 'watcher.added';
    case WatcherRemoved = 'watcher.removed';
    case EscalationTriggered = 'escalation.triggered';
    case EscalationCleared = 'escalation.cleared';
    case TicketMerged = 'ticket.merged';
    case TicketSplit = 'ticket.split';
    case AttachmentAdded = 'attachment.added';
    case AttachmentRemoved = 'attachment.removed';
    case OutboundDelivered = 'outbound.delivered';
    case OutboundFailed = 'outbound.failed';
}
