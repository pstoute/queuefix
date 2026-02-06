export type TicketStatus = 'open' | 'pending' | 'on_hold' | 'resolved' | 'closed';
export type TicketPriority = 'low' | 'normal' | 'high' | 'urgent';
export type UserRole = 'admin' | 'agent';
export type MessageType = 'reply' | 'internal_note';
export type MailboxType = 'imap' | 'gmail' | 'microsoft';

export interface User {
    id: string;
    name: string;
    email: string;
    role: UserRole;
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

export interface Ticket {
    id: string;
    ticket_number: string;
    subject: string;
    status: TicketStatus;
    priority: TicketPriority;
    customer_id: string;
    assigned_to?: string;
    mailbox_id?: string;
    last_activity_at: string;
    created_at: string;
    updated_at: string;
    customer?: Customer;
    assignee?: User;
    messages?: Message[];
    tags?: Tag[];
    mailbox?: Mailbox;
    sla_timer?: SlaTimer;
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
    department?: string;
    polling_interval: number;
    is_active: boolean;
    last_checked_at?: string;
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
    links: {
        first: string;
        last: string;
        prev?: string;
        next?: string;
    };
    meta: {
        current_page: number;
        from: number;
        last_page: number;
        per_page: number;
        to: number;
        total: number;
    };
}
