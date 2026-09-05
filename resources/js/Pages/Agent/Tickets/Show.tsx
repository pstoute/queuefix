import { Head, router, useForm } from '@inertiajs/react';
import { Message, PageProps, Ticket, TicketMergeEvent, User, TicketStatus, TicketPriority } from '@/types';
import AgentLayout from '@/Layouts/AgentLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Textarea } from '@/Components/ui/textarea';
import { Label } from '@/Components/ui/label';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { Separator } from '@/Components/ui/separator';
import { ScrollArea } from '@/Components/ui/scroll-area';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/Components/ui/select';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/Components/ui/popover';
import { cn } from '@/lib/utils';
import { formatRelativeTime, formatDateTime } from '@/lib/hooks';
import { useState } from 'react';
import {
  ArrowLeft,
  Send,
  StickyNote,
  Paperclip,
  User as UserIcon,
  Mail,
  Phone,
  Building,
  Calendar,
  Clock,
  AlertCircle,
  CheckCircle,
  Tag,
  Plus,
  X,
  Eye,
  EyeOff,
  Star,
  GitMerge,
} from 'lucide-react';

interface TicketShowProps extends PageProps {
  ticket: Ticket;
  agents: User[];
  statuses: TicketStatus[];
  priorities: { value: TicketPriority; label: string }[];
  mentionableUsers: Array<Pick<User, 'id' | 'name' | 'handle' | 'avatar'>>;
  canMerge: boolean;
  mergeCandidates: Array<Pick<Ticket, 'id' | 'ticket_number' | 'subject'>>;
}

const priorityConfig = {
  low: { label: 'Low', variant: 'secondary' as const },
  normal: { label: 'Normal', variant: 'default' as const },
  high: { label: 'High', variant: 'outline' as const },
  urgent: { label: 'Urgent', variant: 'destructive' as const },
};

export default function TicketShow({
  ticket,
  agents,
  statuses,
  priorities,
  mentionableUsers,
  canMerge,
  mergeCandidates,
  auth,
}: TicketShowProps) {
  const [replyType, setReplyType] = useState<'reply' | 'internal_note'>('reply');
  const [newTag, setNewTag] = useState('');
  const [editingMessageId, setEditingMessageId] = useState<string | null>(null);
  const [editingBody, setEditingBody] = useState('');
  const [ccInput, setCcInput] = useState('');
  const [mergeTicketId, setMergeTicketId] = useState('');

  const { data, setData, post, processing, reset } = useForm({
    body: '',
    type: 'reply' as 'reply' | 'internal_note',
    cc: ticket.cc_recipients?.map((recipient) => recipient.email) || [] as string[],
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post(`/agent/tickets/${ticket.id}/reply`, {
      preserveScroll: true,
      onSuccess: () => {
        reset('body');
        setData('type', 'reply');
        setReplyType('reply');
      },
    });
  };

  const handleStatusChange = (status: string) => {
    router.patch(`/agent/tickets/${ticket.id}/status`, { status }, { preserveScroll: true });
  };

  const handlePriorityChange = (priority: TicketPriority) => {
    router.patch(`/agent/tickets/${ticket.id}/priority`, { priority }, { preserveScroll: true });
  };

  const handleAssigneeChange = (assignee: string) => {
    router.patch(`/agent/tickets/${ticket.id}/assign`, { assigned_to: assignee }, { preserveScroll: true });
  };

  const handleWatchToggle = () => {
    const options = { preserveScroll: true };

    if (ticket.is_watching) {
      router.delete(`/agent/tickets/${ticket.id}/watch`, options);
    } else {
      router.post(`/agent/tickets/${ticket.id}/watch`, {}, options);
    }
  };

  const handleAddTag = () => {
    if (newTag.trim()) {
      router.post(`/agent/tickets/${ticket.id}/tags`, { name: newTag.trim() }, {
        preserveScroll: true,
        onSuccess: () => setNewTag(''),
      });
    }
  };

  const handleRemoveTag = (tagId: string) => {
    router.delete(`/agent/tickets/${ticket.id}/tags/${tagId}`, { preserveScroll: true });
  };

  const handleMerge = () => {
    const source = mergeCandidates.find((candidate) => candidate.id === mergeTicketId);
    if (!source) return;

    if (!window.confirm(`Merge #${source.ticket_number} into #${ticket.ticket_number}? This cannot be undone.`)) {
      return;
    }

    router.post(`/agent/tickets/${ticket.id}/merge`, { merge_ticket_id: source.id }, {
      preserveScroll: true,
      onSuccess: () => setMergeTicketId(''),
    });
  };

  const addCcRecipient = () => {
    const email = ccInput.trim().toLowerCase();
    if (email && email !== ticket.customer?.email.toLowerCase() && !data.cc.includes(email)) {
      setData('cc', [...data.cc, email]);
      setCcInput('');
    }
  };

  const removeCcRecipient = (email: string) => {
    setData('cc', data.cc.filter((recipient) => recipient !== email));
  };

  const removeTicketCcRecipient = (recipientId: string, email: string) => {
    router.delete(`/agent/tickets/${ticket.id}/cc-recipients/${recipientId}`, {
      preserveScroll: true,
      onSuccess: () => removeCcRecipient(email),
    });
  };

  const mentionSuggestions = (body: string) => {
    const match = body.match(/(?:^|\s)@([A-Za-z0-9_-]*)$/);
    if (!match) return [];

    const query = match[1].toLowerCase();
    return mentionableUsers
      .filter((user) =>
        user.handle.startsWith(query) || user.name.toLowerCase().includes(query)
      )
      .slice(0, 5);
  };

  const insertMention = (body: string, user: Pick<User, 'handle'>) =>
    body.replace(/@[A-Za-z0-9_-]*$/, `@${user.handle} `);

  const renderInternalNote = (message: Message) => {
    const handles = new Set(
      message.mentions
        ?.map((mention) => mention.mentioned_user?.handle.toLowerCase())
        .filter((handle): handle is string => Boolean(handle)) || []
    );

    return (message.body_text || '').split(/(@[A-Za-z0-9][A-Za-z0-9_-]{0,47})/gi).map((part, index) => {
      const handle = part.startsWith('@') ? part.slice(1).toLowerCase() : null;

      return handle && handles.has(handle) ? (
        <span key={`${message.id}-mention-${index}`} className="rounded bg-primary/10 px-1 font-medium text-primary">
          {part}
        </span>
      ) : part;
    });
  };

  const saveInternalNote = (messageId: string) => {
    router.patch(
      `/agent/tickets/${ticket.id}/messages/${messageId}/internal-note`,
      { body: editingBody },
      {
        preserveScroll: true,
        onSuccess: () => {
          setEditingMessageId(null);
          setEditingBody('');
        },
      }
    );
  };

  const getInitials = (name: string) => {
    return name
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  };

  const getSlaStatus = () => {
    if (!ticket.sla_timer?.status_summary) return null;
    const clockStatuses = Object.values(ticket.sla_timer.status_summary).map((clock) => clock.status);

    if (clockStatuses.includes('breached')) {
      return { label: 'Breached', color: 'text-red-600', icon: AlertCircle };
    }

    if (clockStatuses.includes('paused')) {
      return { label: 'Paused', color: 'text-gray-600 dark:text-gray-300', icon: Clock };
    }

    if (clockStatuses.includes('approaching')) {
      return { label: 'Approaching', color: 'text-amber-600', icon: Clock };
    }

    if (clockStatuses.every((status) => status === 'met' || status === 'none')) {
      return { label: 'Met', color: 'text-green-600', icon: CheckCircle };
    }

    return { label: 'On Track', color: 'text-blue-600', icon: Clock };
  };

  const slaStatus = getSlaStatus();
  const formatDuration = (seconds: number) => {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    if (hours > 0) return `${hours}h ${minutes}m`;
    if (minutes > 0) return `${minutes}m`;
    return `${seconds}s`;
  };
  const activePauseSeconds = ticket.sla_timer?.paused_at
    ? Math.max(0, Math.floor((Date.now() - new Date(ticket.sla_timer.paused_at).getTime()) / 1000))
    : 0;
  const timelineItems = [
    ...(ticket.messages || []).map((message) => ({
      kind: 'message' as const,
      id: message.id,
      occurredAt: message.created_at,
      message,
    })),
    ...(ticket.merge_events || []).map((event: TicketMergeEvent) => ({
      kind: 'merge' as const,
      id: event.id,
      occurredAt: event.occurred_at,
      event,
    })),
  ].sort((left, right) => left.occurredAt.localeCompare(right.occurredAt) || left.id.localeCompare(right.id));

  return (
    <AgentLayout>
      <Head title={`Ticket #${ticket.ticket_number}`} />

      <div className="h-full flex flex-col">
        {/* Header */}
        <div className="border-b bg-card">
          <div className="container max-w-7xl mx-auto px-6 py-4">
            <div className="flex items-center gap-4">
              <Button
                variant="ghost"
                size="icon"
                onClick={() => router.get('/agent/tickets')}
              >
                <ArrowLeft className="h-4 w-4" />
              </Button>
              <div className="flex-1">
                <div className="flex items-center gap-2 mb-1">
                  <span className="text-sm font-medium text-muted-foreground">
                    #{ticket.ticket_number}
                  </span>
                  <div
                    className="h-2 w-2 rounded-full"
                    style={{ backgroundColor: ticket.status?.color || '#6b7280' }}
                  />
                  <span className="text-sm text-muted-foreground">
                    {ticket.status?.name || 'Unknown status'}
                  </span>
                </div>
                <h1 className="text-xl font-semibold">{ticket.subject}</h1>
              </div>
              <Button
                type="button"
                variant={ticket.is_watching ? 'default' : 'outline'}
                onClick={handleWatchToggle}
                aria-pressed={ticket.is_watching}
              >
                {ticket.is_watching ? (
                  <EyeOff className="mr-2 h-4 w-4" />
                ) : (
                  <Eye className="mr-2 h-4 w-4" />
                )}
                {ticket.is_watching ? 'Unwatch' : 'Watch'}
              </Button>
            </div>
          </div>
        </div>

        {/* Main content */}
        <div className="flex-1 overflow-hidden">
          <div className="container max-w-7xl mx-auto p-6 h-full">
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 h-full">
              {/* Left: Message thread */}
              <div className="lg:col-span-2 flex flex-col min-h-0">
                <Card className="flex-1 flex flex-col overflow-hidden min-h-0">
                  {/* Messages */}
                  <ScrollArea className="flex-1 p-6">
                    <div className="space-y-6">
                      {timelineItems.map((entry) => {
                        if (entry.kind === 'merge') {
                          const event = entry.event;
                          const counterpart = event.counterpart_ticket?.ticket_number || 'unknown';
                          const description = event.event_type === 'target_received'
                            ? `Ticket #${counterpart} was merged into this ticket.`
                            : `This ticket was merged into #${counterpart}.`;

                          return (
                            <div
                              key={event.id}
                              className="flex items-start gap-3 rounded-lg border border-dashed bg-muted/40 p-4"
                            >
                              <GitMerge className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                              <div>
                                <p className="text-sm font-medium">{description}</p>
                                <p className="text-xs text-muted-foreground">
                                  {event.actor?.name || 'Former staff member'} · {formatDateTime(event.occurred_at)}
                                </p>
                              </div>
                            </div>
                          );
                        }

                        const message = entry.message;
                        const isInternal = message.type === 'internal_note';
                        const isCustomer = message.sender_type === 'App\\Models\\Customer';
                        const sender = message.sender as User | undefined;

                        return (
                          <div
                            key={message.id}
                            id={`message-${message.id}`}
                            className={cn(
                              'rounded-lg p-4',
                              isInternal
                                ? 'bg-amber-50 dark:bg-amber-950 border-2 border-amber-200 dark:border-amber-800'
                                : isCustomer
                                ? 'bg-muted'
                                : 'bg-blue-50 dark:bg-blue-950'
                            )}
                          >
                            {/* Message header */}
                            <div className="flex items-start gap-3 mb-3">
                              <Avatar className="h-8 w-8">
                                <AvatarImage src={sender?.avatar} alt={sender?.name} />
                                <AvatarFallback>
                                  {sender?.name ? getInitials(sender.name) : '?'}
                                </AvatarFallback>
                              </Avatar>
                              <div className="flex-1 min-w-0">
                                <div className="flex items-center gap-2 mb-1">
                                  <span className="font-medium text-sm">
                                    {sender?.name || 'Unknown'}
                                  </span>
                                  {isInternal && (
                                    <Badge variant="outline" className="text-xs">
                                      Internal Note
                                    </Badge>
                                  )}
                                  {message.original_ticket && message.original_ticket.id !== ticket.id && (
                                    <Badge variant="secondary" className="text-xs">
                                      Originally #{message.original_ticket.ticket_number}
                                    </Badge>
                                  )}
                                  {isInternal
                                    && message.sender_type === 'App\\Models\\User'
                                    && message.sender_id === auth.user.id && (
                                    <Button
                                      type="button"
                                      variant="ghost"
                                      size="sm"
                                      className="ml-auto h-7"
                                      onClick={() => {
                                        setEditingMessageId(message.id);
                                        setEditingBody(message.body_text || '');
                                      }}
                                    >
                                      Edit
                                    </Button>
                                  )}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                  {formatDateTime(message.created_at)}
                                </p>
                              </div>
                            </div>

                            {/* Message body */}
                            {isInternal && editingMessageId === message.id ? (
                              <div className="space-y-2">
                                <Textarea
                                  value={editingBody}
                                  onChange={(event) => setEditingBody(event.target.value)}
                                  rows={4}
                                  className="bg-background"
                                />
                                {mentionSuggestions(editingBody).length > 0 && (
                                  <div className="rounded-md border bg-popover p-1" role="listbox" aria-label="Mention staff">
                                    {mentionSuggestions(editingBody).map((user) => (
                                      <button
                                        key={user.id}
                                        type="button"
                                        role="option"
                                        className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                                        onClick={() => setEditingBody(insertMention(editingBody, user))}
                                      >
                                        <span className="font-medium">@{user.handle}</span>
                                        <span className="text-muted-foreground">{user.name}</span>
                                      </button>
                                    ))}
                                  </div>
                                )}
                                <div className="flex justify-end gap-2">
                                  <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                      setEditingMessageId(null);
                                      setEditingBody('');
                                    }}
                                  >
                                    Cancel
                                  </Button>
                                  <Button
                                    type="button"
                                    size="sm"
                                    disabled={!editingBody.trim()}
                                    onClick={() => saveInternalNote(message.id)}
                                  >
                                    Save note
                                  </Button>
                                </div>
                              </div>
                            ) : isInternal ? (
                              <div className="whitespace-pre-wrap break-words text-sm">
                                {renderInternalNote(message)}
                              </div>
                            ) : (
                              <div
                                className="prose prose-sm max-w-none dark:prose-invert"
                                dangerouslySetInnerHTML={{
                                  __html: message.body_html || message.body_text || '',
                                }}
                              />
                            )}

                            {!isInternal && message.cc_recipients && message.cc_recipients.length > 0 && (
                              <div className="mt-3 flex flex-wrap items-center gap-1 border-t pt-3 text-xs text-muted-foreground">
                                <span>CC:</span>
                                {message.cc_recipients.map((recipient) => (
                                  <Badge key={recipient.id} variant="outline" className="font-normal">
                                    {recipient.display_name || recipient.email}
                                  </Badge>
                                ))}
                              </div>
                            )}

                            {/* Attachments */}
                            {message.attachments && message.attachments.length > 0 && (
                              <div className="mt-3 pt-3 border-t space-y-2">
                                {message.attachments.map((attachment) => (
                                  <a
                                    key={attachment.id}
                                    href={attachment.url}
                                    className="flex items-center gap-2 text-sm text-primary hover:underline"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                  >
                                    <Paperclip className="h-4 w-4" />
                                    {attachment.filename}
                                    <span className="text-xs text-muted-foreground">
                                      ({Math.round(attachment.size / 1024)} KB)
                                    </span>
                                  </a>
                                ))}
                              </div>
                            )}
                          </div>
                        );
                      })}
                    </div>
                  </ScrollArea>

                  <Separator />

                  {/* Reply composer */}
                  <form onSubmit={handleSubmit} className="p-6 space-y-4">
                    <div className="flex items-center gap-2">
                      <Button
                        type="button"
                        variant={replyType === 'reply' ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => {
                          setReplyType('reply');
                          setData('type', 'reply');
                          setData('cc', ticket.cc_recipients?.map((recipient) => recipient.email) || []);
                        }}
                      >
                        <Send className="mr-2 h-4 w-4" />
                        Reply
                      </Button>
                      <Button
                        type="button"
                        variant={replyType === 'internal_note' ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => {
                          setReplyType('internal_note');
                          setData('type', 'internal_note');
                          setData('cc', []);
                        }}
                      >
                        <StickyNote className="mr-2 h-4 w-4" />
                        Internal Note
                      </Button>
                    </div>

                    <Textarea
                      placeholder={
                        replyType === 'reply'
                          ? 'Type your reply...'
                          : 'Add an internal note (only visible to agents)...'
                      }
                      value={data.body}
                      onChange={(e) => setData('body', e.target.value)}
                      rows={6}
                      className={cn(
                        replyType === 'internal_note' &&
                          'bg-amber-50 dark:bg-amber-950 border-amber-200 dark:border-amber-800'
                      )}
                    />

                    {replyType === 'internal_note' && mentionSuggestions(data.body).length > 0 && (
                      <div className="rounded-md border bg-popover p-1" role="listbox" aria-label="Mention staff">
                        {mentionSuggestions(data.body).map((user) => (
                          <button
                            key={user.id}
                            type="button"
                            role="option"
                            className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                            onClick={() => setData('body', insertMention(data.body, user))}
                          >
                            <span className="font-medium">@{user.handle}</span>
                            <span className="text-muted-foreground">{user.name}</span>
                          </button>
                        ))}
                      </div>
                    )}

                    {replyType === 'reply' && (
                      <div className="space-y-2 rounded-md border bg-muted/30 p-3">
                        <div className="text-xs text-muted-foreground">
                          <span className="font-medium text-foreground">To:</span> {ticket.customer?.email}
                        </div>
                        <div className="flex flex-wrap items-center gap-1">
                          <span className="text-xs font-medium">CC:</span>
                          {data.cc.length === 0 && (
                            <span className="text-xs text-muted-foreground">No additional recipients</span>
                          )}
                          {data.cc.map((email) => (
                            <Badge key={email} variant="outline" className="gap-1 font-normal">
                              {email}
                              <button type="button" onClick={() => removeCcRecipient(email)} aria-label={`Remove ${email}`}>
                                <X className="h-3 w-3" />
                              </button>
                            </Badge>
                          ))}
                        </div>
                        <div className="flex gap-2">
                          <input
                            type="email"
                            value={ccInput}
                            onChange={(event) => setCcInput(event.target.value)}
                            onKeyDown={(event) => {
                              if (event.key === 'Enter') {
                                event.preventDefault();
                                addCcRecipient();
                              }
                            }}
                            placeholder="Add CC email"
                            className="h-9 flex-1 rounded-md border bg-background px-3 text-sm"
                          />
                          <Button type="button" variant="outline" size="sm" onClick={addCcRecipient}>
                            Add CC
                          </Button>
                        </div>
                      </div>
                    )}

                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <Popover>
                          <PopoverTrigger asChild>
                            <Button type="button" variant="outline" size="sm">
                              Canned Responses
                            </Button>
                          </PopoverTrigger>
                          <PopoverContent className="w-80">
                            <p className="text-sm text-muted-foreground">
                              Canned responses feature coming soon
                            </p>
                          </PopoverContent>
                        </Popover>
                      </div>

                      <Button type="submit" disabled={processing || !data.body.trim()}>
                        <Send className="mr-2 h-4 w-4" />
                        {replyType === 'reply' ? 'Send Reply' : 'Add Note'}
                      </Button>
                    </div>
                  </form>
                </Card>
              </div>

              {/* Right: Sidebar */}
              <div className="space-y-6 overflow-y-auto">
                {ticket.rating && (
                  <Card>
                    <CardHeader>
                      <CardTitle className="text-base flex items-center gap-2">
                        <Star className="h-4 w-4" />
                        Customer rating
                      </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                      <div className="flex items-center gap-1" aria-label={`${ticket.rating.rating} out of 5`}>
                        {[1, 2, 3, 4, 5].map((score) => (
                          <Star
                            key={score}
                            className={cn(
                              'h-5 w-5',
                              score <= ticket.rating!.rating
                                ? 'fill-amber-400 text-amber-400'
                                : 'text-muted-foreground'
                            )}
                          />
                        ))}
                        <span className="ml-2 text-sm font-medium">{ticket.rating.rating}/5</span>
                      </div>
                      {ticket.rating.feedback && (
                        <p className="whitespace-pre-wrap break-words text-sm text-muted-foreground">
                          {ticket.rating.feedback}
                        </p>
                      )}
                      <p className="text-xs text-muted-foreground">
                        Submitted {formatDateTime(ticket.rating.submitted_at)}
                      </p>
                    </CardContent>
                  </Card>
                )}

                {/* Ticket metadata */}
                <Card>
                  <CardHeader>
                    <CardTitle className="text-base">Ticket Details</CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-4">
                    {/* Status */}
                    <div className="space-y-2">
                      <Label className="text-xs text-muted-foreground">Status</Label>
                      <Select value={ticket.status?.slug} onValueChange={handleStatusChange}>
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {statuses.map((status) => (
                            <SelectItem key={status.id} value={status.slug}>
                              {status.name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>

                    {/* Priority */}
                    <div className="space-y-2">
                      <Label className="text-xs text-muted-foreground">Priority</Label>
                      <Select value={ticket.priority} onValueChange={handlePriorityChange}>
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {priorities.map((priority) => (
                            <SelectItem key={priority.value} value={priority.value}>
                              {priority.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>

                    {/* Assignee */}
                    <div className="space-y-2">
                      <Label className="text-xs text-muted-foreground">Assignee</Label>
                      <Select
                        value={ticket.assigned_to || 'unassigned'}
                        onValueChange={handleAssigneeChange}
                      >
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="unassigned">Unassigned</SelectItem>
                          {agents.map((agent) => (
                            <SelectItem key={agent.id} value={agent.id}>
                              {agent.name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>

                    <Separator />

                    {/* Tags */}
                    <div className="space-y-2">
                      <Label className="text-xs text-muted-foreground">Tags</Label>
                      <div className="flex flex-wrap gap-1 mb-2">
                        {ticket.tags?.map((tag) => (
                          <Badge
                            key={tag.id}
                            variant="outline"
                            className="group"
                            style={{
                              borderColor: tag.color,
                              color: tag.color,
                            }}
                          >
                            {tag.name}
                            <button
                              type="button"
                              onClick={() => handleRemoveTag(tag.id)}
                              className="ml-1 opacity-0 group-hover:opacity-100 transition-opacity"
                            >
                              <X className="h-3 w-3" />
                            </button>
                          </Badge>
                        ))}
                      </div>
                      <div className="flex gap-2">
                        <input
                          type="text"
                          placeholder="Add tag..."
                          value={newTag}
                          onChange={(e) => setNewTag(e.target.value)}
                          onKeyPress={(e) => {
                            if (e.key === 'Enter') {
                              e.preventDefault();
                              handleAddTag();
                            }
                          }}
                          className="flex-1 h-8 px-2 text-sm rounded-md border bg-background"
                        />
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={handleAddTag}
                        >
                          <Plus className="h-4 w-4" />
                        </Button>
                      </div>
                    </div>
                  </CardContent>
                </Card>

                {canMerge && (
                  <Card>
                    <CardHeader>
                      <CardTitle className="text-base flex items-center gap-2">
                        <GitMerge className="h-4 w-4" />
                        Merge duplicate
                      </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                      <p className="text-xs text-muted-foreground">
                        Move another ticket from this customer into #{ticket.ticket_number}. Its messages keep their original ticket provenance. This cannot be undone.
                      </p>
                      {mergeCandidates.length > 0 ? (
                        <>
                          <Label htmlFor="merge-ticket" className="sr-only">Source ticket</Label>
                          <Select value={mergeTicketId} onValueChange={setMergeTicketId}>
                            <SelectTrigger id="merge-ticket">
                              <SelectValue placeholder="Choose a source ticket" />
                            </SelectTrigger>
                            <SelectContent>
                              {mergeCandidates.map((candidate) => (
                                <SelectItem key={candidate.id} value={candidate.id}>
                                  #{candidate.ticket_number} · {candidate.subject}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                          <Button
                            type="button"
                            variant="destructive"
                            className="w-full"
                            disabled={!mergeTicketId}
                            onClick={handleMerge}
                          >
                            Merge into this ticket
                          </Button>
                        </>
                      ) : (
                        <p className="text-sm text-muted-foreground">No eligible tickets for this customer.</p>
                      )}
                    </CardContent>
                  </Card>
                )}

                {/* CC recipients */}
                <Card>
                  <CardHeader>
                    <CardTitle className="text-base">CC Recipients</CardTitle>
                  </CardHeader>
                  <CardContent>
                    {ticket.cc_recipients && ticket.cc_recipients.length > 0 ? (
                      <div className="space-y-2">
                        {ticket.cc_recipients.map((recipient) => (
                          <div key={recipient.id} className="flex items-center justify-between gap-2 text-sm">
                            <div className="min-w-0">
                              {recipient.display_name && <div className="truncate font-medium">{recipient.display_name}</div>}
                              <div className="truncate text-muted-foreground">{recipient.email}</div>
                            </div>
                            <Button
                              type="button"
                              variant="ghost"
                              size="icon"
                              aria-label={`Remove ${recipient.email}`}
                              onClick={() => removeTicketCcRecipient(recipient.id, recipient.email)}
                            >
                              <X className="h-4 w-4" />
                            </Button>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <p className="text-sm text-muted-foreground">No approved CC recipients.</p>
                    )}
                  </CardContent>
                </Card>

                {/* Watchers */}
                <Card>
                  <CardHeader>
                    <CardTitle className="text-base flex items-center gap-2">
                      <Eye className="h-4 w-4" />
                      Watchers
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    {ticket.watchers && ticket.watchers.length > 0 ? (
                      <div className="space-y-3">
                        {ticket.watchers.map((watcher) => (
                          <div key={watcher.id} className="flex items-center gap-3">
                            <Avatar className="h-8 w-8">
                              <AvatarImage src={watcher.avatar} alt={watcher.name} />
                              <AvatarFallback>{getInitials(watcher.name)}</AvatarFallback>
                            </Avatar>
                            <div className="min-w-0">
                              <div className="truncate text-sm font-medium">{watcher.name}</div>
                              <div className="truncate text-xs text-muted-foreground">{watcher.email}</div>
                            </div>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <p className="text-sm text-muted-foreground">No agents are watching this ticket.</p>
                    )}
                  </CardContent>
                </Card>

                {/* SLA Timer */}
                {ticket.sla_timer && slaStatus && (
                  <Card>
                    <CardHeader>
                      <CardTitle className="text-base flex items-center gap-2">
                        <Clock className="h-4 w-4" />
                        SLA Timer
                      </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                      <div className="flex items-center justify-between">
                        <span className="text-sm font-medium">Status</span>
                        <div className={cn('flex items-center gap-1', slaStatus.color)}>
                          <slaStatus.icon className="h-4 w-4" />
                          <span className="text-sm font-medium">{slaStatus.label}</span>
                        </div>
                      </div>

                      <Separator />

                      <div className="space-y-1">
                        <div className="text-xs text-muted-foreground">Total paused</div>
                        <div className="text-sm">
                          {formatDuration(ticket.sla_timer.total_paused_seconds + activePauseSeconds)}
                        </div>
                      </div>

                      {ticket.sla_timer.pause_intervals && ticket.sla_timer.pause_intervals.length > 0 && (
                        <div className="space-y-2">
                          <div className="text-xs text-muted-foreground">Pause history</div>
                          {ticket.sla_timer.pause_intervals.map((interval) => (
                            <div key={interval.id} className="rounded-md border p-2 text-xs">
                              <div>{formatDateTime(interval.started_at)}</div>
                              <div className="text-muted-foreground">
                                {interval.ended_at
                                  ? `${formatDuration(interval.duration_seconds)} · resumed ${formatRelativeTime(interval.ended_at)}`
                                  : 'Currently paused'}
                              </div>
                            </div>
                          ))}
                        </div>
                      )}

                      <Separator />

                      {ticket.sla_timer.first_response_due_at && (
                        <div className="space-y-1">
                          <div className="text-xs text-muted-foreground">First Response</div>
                          {ticket.sla_timer.first_responded_at ? (
                            <div className="text-sm">
                              <CheckCircle className="inline h-3 w-3 text-green-600 mr-1" />
                              Met {formatRelativeTime(ticket.sla_timer.first_responded_at)}
                            </div>
                          ) : (
                            <div className="text-sm">
                              Due {formatRelativeTime(ticket.sla_timer.first_response_due_at)}
                            </div>
                          )}
                        </div>
                      )}

                      {ticket.sla_timer.resolution_due_at && (
                        <div className="space-y-1">
                          <div className="text-xs text-muted-foreground">Resolution</div>
                          {ticket.sla_timer.resolved_at ? (
                            <div className="text-sm">
                              <CheckCircle className="inline h-3 w-3 text-green-600 mr-1" />
                              Met {formatRelativeTime(ticket.sla_timer.resolved_at)}
                            </div>
                          ) : (
                            <div className="text-sm">
                              Due {formatRelativeTime(ticket.sla_timer.resolution_due_at)}
                            </div>
                          )}
                        </div>
                      )}
                    </CardContent>
                  </Card>
                )}

                {/* Customer info */}
                {ticket.customer && (
                  <Card>
                    <CardHeader>
                      <CardTitle className="text-base">Customer</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                      <div className="flex items-center gap-3">
                        <Avatar>
                          <AvatarImage src={ticket.customer.avatar} alt={ticket.customer.name} />
                          <AvatarFallback>
                            {getInitials(ticket.customer.name)}
                          </AvatarFallback>
                        </Avatar>
                        <div className="flex-1 min-w-0">
                          <div className="font-medium">{ticket.customer.name}</div>
                        </div>
                      </div>

                      <Separator />

                      <div className="space-y-2 text-sm">
                        <div className="flex items-center gap-2">
                          <Mail className="h-4 w-4 text-muted-foreground" />
                          <a
                            href={`mailto:${ticket.customer.email}`}
                            className="text-primary hover:underline"
                          >
                            {ticket.customer.email}
                          </a>
                        </div>
                        {ticket.customer.phone && (
                          <div className="flex items-center gap-2">
                            <Phone className="h-4 w-4 text-muted-foreground" />
                            <a
                              href={`tel:${ticket.customer.phone}`}
                              className="text-primary hover:underline"
                            >
                              {ticket.customer.phone}
                            </a>
                          </div>
                        )}
                        {ticket.customer.company && (
                          <div className="flex items-center gap-2">
                            <Building className="h-4 w-4 text-muted-foreground" />
                            <span>{ticket.customer.company}</span>
                          </div>
                        )}
                      </div>
                    </CardContent>
                  </Card>
                )}

                {/* Dates */}
                <Card>
                  <CardHeader>
                    <CardTitle className="text-base">Timestamps</CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-2 text-sm">
                    <div className="flex items-center justify-between">
                      <span className="text-muted-foreground">Created</span>
                      <span>{formatDateTime(ticket.created_at)}</span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-muted-foreground">Last Activity</span>
                      <span>{formatRelativeTime(ticket.last_activity_at)}</span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-muted-foreground">Updated</span>
                      <span>{formatRelativeTime(ticket.updated_at)}</span>
                    </div>
                  </CardContent>
                </Card>
              </div>
            </div>
          </div>
        </div>
      </div>
    </AgentLayout>
  );
}
