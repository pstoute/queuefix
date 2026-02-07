import { Head, router, useForm } from '@inertiajs/react';
import { PageProps, Ticket, User, TicketStatus, TicketPriority } from '@/types';
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
} from 'lucide-react';

interface TicketShowProps extends PageProps {
  ticket: Ticket;
  agents: User[];
  statuses: { value: TicketStatus; label: string }[];
  priorities: { value: TicketPriority; label: string }[];
}

const statusConfig = {
  open: { label: 'Open', color: 'bg-green-500' },
  pending: { label: 'Pending', color: 'bg-amber-500' },
  on_hold: { label: 'On Hold', color: 'bg-gray-500' },
  resolved: { label: 'Resolved', color: 'bg-blue-500' },
  closed: { label: 'Closed', color: 'bg-gray-500' },
};

const priorityConfig = {
  low: { label: 'Low', variant: 'secondary' as const },
  normal: { label: 'Normal', variant: 'default' as const },
  high: { label: 'High', variant: 'outline' as const },
  urgent: { label: 'Urgent', variant: 'destructive' as const },
};

export default function TicketShow({ ticket, agents, statuses, priorities }: TicketShowProps) {
  const [replyType, setReplyType] = useState<'reply' | 'internal_note'>('reply');
  const [newTag, setNewTag] = useState('');

  const { data, setData, post, processing, reset } = useForm({
    body: '',
    type: 'reply' as 'reply' | 'internal_note',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post(`/agent/tickets/${ticket.id}/reply`, {
      preserveScroll: true,
      onSuccess: () => {
        reset();
        setReplyType('reply');
      },
    });
  };

  const handleStatusChange = (status: TicketStatus) => {
    router.patch(`/agent/tickets/${ticket.id}/status`, { status }, { preserveScroll: true });
  };

  const handlePriorityChange = (priority: TicketPriority) => {
    router.patch(`/agent/tickets/${ticket.id}/priority`, { priority }, { preserveScroll: true });
  };

  const handleAssigneeChange = (assignee: string) => {
    router.patch(`/agent/tickets/${ticket.id}/assign`, { assigned_to: assignee }, { preserveScroll: true });
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

  const getInitials = (name: string) => {
    return name
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  };

  const getSlaStatus = () => {
    if (!ticket.sla_timer) return null;

    if (ticket.sla_timer.first_response_breached || ticket.sla_timer.resolution_breached) {
      return { label: 'Breached', color: 'text-red-600', icon: AlertCircle };
    }

    if (ticket.sla_timer.first_responded_at && ticket.sla_timer.resolved_at) {
      return { label: 'Met', color: 'text-green-600', icon: CheckCircle };
    }

    // Calculate time remaining for next due date
    const now = new Date();
    const nextDue = ticket.sla_timer.first_response_due_at && !ticket.sla_timer.first_responded_at
      ? new Date(ticket.sla_timer.first_response_due_at)
      : ticket.sla_timer.resolution_due_at
      ? new Date(ticket.sla_timer.resolution_due_at)
      : null;

    if (nextDue) {
      const hoursRemaining = Math.max(0, (nextDue.getTime() - now.getTime()) / (1000 * 60 * 60));
      if (hoursRemaining < 2) {
        return { label: 'Due Soon', color: 'text-amber-600', icon: Clock };
      }
    }

    return { label: 'On Track', color: 'text-blue-600', icon: Clock };
  };

  const slaStatus = getSlaStatus();

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
                    className={cn(
                      'h-2 w-2 rounded-full',
                      statusConfig[ticket.status].color
                    )}
                  />
                  <span className="text-sm text-muted-foreground">
                    {statusConfig[ticket.status].label}
                  </span>
                </div>
                <h1 className="text-xl font-semibold">{ticket.subject}</h1>
              </div>
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
                      {ticket.messages?.map((message) => {
                        const isInternal = message.type === 'internal_note';
                        const isCustomer = message.sender_type === 'App\\Models\\Customer';
                        const sender = message.sender as User | undefined;

                        return (
                          <div
                            key={message.id}
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
                                </div>
                                <p className="text-xs text-muted-foreground">
                                  {formatDateTime(message.created_at)}
                                </p>
                              </div>
                            </div>

                            {/* Message body */}
                            <div
                              className="prose prose-sm max-w-none dark:prose-invert"
                              dangerouslySetInnerHTML={{
                                __html: message.body_html || message.body_text || '',
                              }}
                            />

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
                {/* Ticket metadata */}
                <Card>
                  <CardHeader>
                    <CardTitle className="text-base">Ticket Details</CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-4">
                    {/* Status */}
                    <div className="space-y-2">
                      <Label className="text-xs text-muted-foreground">Status</Label>
                      <Select value={ticket.status} onValueChange={handleStatusChange}>
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {statuses.map((status) => (
                            <SelectItem key={status.value} value={status.value}>
                              {status.label}
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
