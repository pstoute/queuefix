export type TicketPriority = 'low' | 'normal' | 'high' | 'urgent';
export type UserRole = 'admin' | 'agent';
export type MessageType = 'reply' | 'internal_note';
export type MailboxType = 'imap' | 'gmail' | 'microsoft';

export interface User {
    id: string;
    name: string;
    handle: string;
    email: string;
    role: UserRole;
    is_support_manager: boolean;
    avatar?: string;
    is_active: boolean;
    email_verified_at?: string;
    created_at: string;
    updated_at: string;
}

export interface Customer {
    id: string;
    name: string;
    email: string;
    phone?: string;
    company?: string;
    avatar?: string;
    created_at: string;
    updated_at: string;
}

export interface Department {
    id: string;
    name: string;
    description?: string;
    is_catch_all: boolean;
    tickets_count?: number;
    mailboxes_count?: number;
    aliases_count?: number;
    created_at?: string;
    updated_at?: string;
}

export interface TicketStatus {
    id: string;
    name: string;
    slug: string;
    color: string;
    icon?: string | null;
    sort_order: number;
    is_default: boolean;
    is_closed: boolean;
    is_system: boolean;
    is_customer_visible: boolean;
    pauses_sla: boolean;
    tickets_count?: number;
    deleted_at?: string | null;
    created_at?: string;
    updated_at?: string;
}

export interface CustomerTicketStatus {
    name: string;
    slug?: string;
    color: string;
    is_closed: boolean;
}

export interface MailboxAlias {
    id: string;
    mailbox_id: string;
    email: string;
    department_id: string;
    department?: Department;
}

export interface Ticket {
    id: string;
    ticket_number: string;
    subject: string;
    ticket_status_id: string;
    status?: TicketStatus;
    customer_status?: CustomerTicketStatus;
    priority: TicketPriority;
    customer_id: string;
    assigned_to?: string;
    mailbox_id?: string;
    department_id?: string;
    last_activity_at: string;
    resolved_at?: string | null;
    closed_at?: string | null;
    created_at: string;
    updated_at: string;
    customer?: Customer;
    assignee?: User;
    department?: Department;
    messages?: Message[];
    tags?: Tag[];
    mailbox?: Mailbox;
    sla_timer?: SlaTimer;
    watchers?: User[];
    is_watching?: boolean;
    unread_count?: number;
    cc_recipients?: TicketCcRecipient[];
    rating?: TicketRating | null;
    merged_into_ticket_id?: string | null;
    merged_at?: string | null;
    merged_by?: string | null;
    merge_events?: TicketMergeEvent[];
    split_events?: TicketSplitEvent[];
}

export interface TicketMergeEvent {
    id: string;
    ticket_id: string;
    counterpart_ticket_id: string;
    actor_id?: string | null;
    event_type: 'source_merged' | 'target_received';
    occurred_at: string;
    actor?: Pick<User, 'id' | 'name'> | null;
    counterpart_ticket?: Pick<Ticket, 'id' | 'ticket_number'>;
}

export interface TicketSplitEvent {
    id: string;
    ticket_id: string;
    counterpart_ticket_id: string;
    actor_id?: string | null;
    event_type: 'source_split' | 'new_ticket_created';
    message_count: number;
    occurred_at: string;
    actor?: Pick<User, 'id' | 'name'> | null;
    counterpart_ticket?: Pick<Ticket, 'id' | 'ticket_number'>;
}

export interface TicketRating {
    id: string;
    ticket_id: string;
    customer_id: string;
    rating: number;
    feedback?: string | null;
    submitted_at: string;
    staff_notified_at?: string | null;
    customer?: Pick<Customer, 'id' | 'name'>;
}

export interface Message {
    id: string;
    ticket_id: string;
    sender_type: string;
    sender_id: string;
    type: MessageType;
    body_text?: string;
    body_html?: string;
    created_at: string;
    updated_at: string;
    sender?: User | Customer;
    attachments?: Attachment[];
    mentions?: TicketMention[];
    cc_recipients?: MessageCcRecipient[];
    original_ticket_id?: string | null;
    original_ticket?: Pick<Ticket, 'id' | 'ticket_number'> | null;
}

export interface TicketCcRecipient {
    id: string;
    ticket_id: string;
    email: string;
    display_name?: string | null;
    source: string;
    validation_state: 'approved';
    approved_at?: string | null;
    removed_at?: string | null;
}

export interface MessageCcRecipient {
    id: string;
    message_id: string;
    email: string;
    display_name?: string | null;
    source: string;
    validation_state: 'approved';
    delivered_at?: string | null;
}

export interface TicketMention {
    id: string;
    ticket_id: string;
    message_id: string;
    mentioned_user_id?: string | null;
    actor_id?: string | null;
    notified_at?: string | null;
    removed_at?: string | null;
    mentioned_user?: Pick<User, 'id' | 'handle'>;
}

export interface Attachment {
    id: string;
    message_id: string;
    filename: string;
    path: string;
    mime_type: string;
    size: number;
    url?: string;
}

export interface Mailbox {
    id: string;
    name: string;
    email: string;
    type: MailboxType;
    department_id?: string;
    department?: Department;
    aliases?: MailboxAlias[];
    polling_interval: number;
    is_active: boolean;
    last_checked_at?: string | null;
    last_fetch_attempted_at?: string | null;
    last_fetch_succeeded_at?: string | null;
    provider_cursor?: string | null;
    consecutive_fetch_failures: number;
    last_fetch_error_category?: 'authentication' | 'transient' | 'provider' | 'processing' | 'configuration' | null;
    last_fetch_error_code?: string | null;
    last_fetch_error_message?: string | null;
    next_fetch_at?: string | null;
    last_processing_failed_at?: string | null;
    last_processing_error_code?: string | null;
    last_processing_error_message?: string | null;
    health_status: 'inactive' | 'authentication_required' | 'fetch_failing' | 'processing_failing' | 'fetching' | 'queued' | 'never_fetched' | 'stale' | 'healthy';
    queue: {
        status: 'idle' | 'queued' | 'running';
        queued_at?: string | null;
        started_at?: string | null;
        pending_messages: number;
        processing_failures: number;
    };
    created_at: string;
    updated_at: string;
}

export interface Tag {
    id: string;
    name: string;
    color: string;
}

export interface CannedResponse {
    id: string;
    title: string;
    body: string;
    is_active: boolean;
    visibility: 'all_agents' | 'creator_only';
    created_by: string;
    creator?: User;
    created_at: string;
    updated_at: string;
}

export interface SlaPolicy {
    id: string;
    name: string;
    priority: TicketPriority;
    first_response_hours: number;
    resolution_hours: number;
    is_active: boolean;
}

export interface SlaTimer {
    id: string;
    ticket_id: string;
    sla_policy_id: string;
    first_response_due_at?: string;
    first_responded_at?: string;
    resolution_due_at?: string;
    resolved_at?: string;
    paused_at?: string;
    total_paused_seconds: number;
    first_response_breached: boolean;
    resolution_breached: boolean;
    sla_policy?: SlaPolicy;
    pause_intervals?: SlaPauseInterval[];
    status_summary?: {
        first_response: SlaClockStatus;
        resolution: SlaClockStatus;
    };
}

export interface SlaClockStatus {
    status: 'none' | 'met' | 'paused' | 'on_track' | 'approaching' | 'breached';
    color: string;
}

export interface SlaPauseInterval {
    id: string;
    sla_timer_id: string;
    started_at: string;
    ended_at?: string | null;
    duration_seconds: number;
}

export type EscalationTrigger = 'no_first_response' | 'no_activity' | 'sla_approaching' | 'sla_breached' | 'status_entered' | 'priority_changed';

export interface EscalationRule {
    id: string;
    name: string;
    trigger: EscalationTrigger;
    trigger_config: Record<string, unknown>;
    filters: Record<string, unknown>;
    actions: Array<Record<string, unknown>>;
    include_closed: boolean;
    include_archived: boolean;
    is_active: boolean;
    created_by?: string | null;
    creator?: Pick<User, 'id' | 'name'> | null;
    last_previewed_at?: string | null;
    logs_count?: number;
    created_at: string;
    updated_at: string;
}

export interface EscalationActionLog {
    id: string;
    escalation_log_id: string;
    escalation_rule_id: string;
    ticket_id: string;
    attempt: number;
    action_order: number;
    action_type: string;
    status: 'applied' | 'failed';
    actor: 'system';
    before_context?: Record<string, unknown> | null;
    after_context?: Record<string, unknown> | null;
    error?: string | null;
    occurred_at: string;
}

export interface EscalationLog {
    id: string;
    escalation_rule_id: string;
    ticket_id: string;
    trigger_window: string;
    trigger_context: Record<string, unknown>;
    status: 'pending' | 'processing' | 'applied' | 'failed' | 'skipped';
    attempts: number;
    actor: 'system';
    started_at?: string | null;
    completed_at?: string | null;
    error?: string | null;
    rule?: Pick<EscalationRule, 'id' | 'name'>;
    ticket?: Pick<Ticket, 'id' | 'ticket_number' | 'subject'>;
    action_logs?: EscalationActionLog[];
    created_at: string;
    updated_at: string;
}

export interface Setting {
    id: string;
    key: string;
    value?: string;
    group: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash?: {
        success?: string;
        error?: string;
    };
    appName: string;
    demo?: {
        enabled: boolean;
        githubUrl: string;
        resetInterval: number;
        credentials: {
            admin: { email: string; password: string };
            agent: { email: string; password: string };
        };
    } | null;
};

export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    from: number;
    last_page: number;
    per_page: number;
    to: number;
    total: number;
    first_page_url: string;
    last_page_url: string;
    prev_page_url: string | null;
    next_page_url: string | null;
    path: string;
    links: { url: string | null; label: string; active: boolean }[];
}
