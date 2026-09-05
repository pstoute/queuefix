import { FormEvent, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import {
  Department,
  EscalationLog,
  EscalationRule,
  EscalationTrigger,
  Mailbox,
  PageProps,
  Tag,
  Ticket,
  TicketPriority,
  TicketStatus,
  User,
} from '@/types';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import { AlertTriangle, CheckCircle2, Clock, Play, Plus, Siren, XCircle } from 'lucide-react';

interface PreviewResult {
  rule_id: string;
  rule_name: string;
  ticket_id: string;
  ticket_number: string;
  matched: boolean;
  eligible: boolean;
  trigger_matched: boolean;
  filters_matched: boolean;
  reasons: string[];
  actions: Array<Record<string, unknown>>;
}

interface EscalationIndexProps extends PageProps {
  rules: EscalationRule[];
  logs: EscalationLog[];
  tickets: Array<Pick<Ticket, 'id' | 'ticket_number' | 'subject'> & { customer?: Pick<User, 'id' | 'name'> }>;
  statuses: Array<Pick<TicketStatus, 'id' | 'name' | 'slug' | 'is_closed'>>;
  priorities: Array<{ value: TicketPriority; label: string }>;
  departments: Array<Pick<Department, 'id' | 'name'>>;
  mailboxes: Array<Pick<Mailbox, 'id' | 'name'>>;
  agents: Array<Pick<User, 'id' | 'name'>>;
  tags: Array<Pick<Tag, 'id' | 'name'>>;
  preview?: PreviewResult | null;
}

const triggerLabels: Record<EscalationTrigger, string> = {
  no_first_response: 'No first response',
  no_activity: 'No activity',
  sla_approaching: 'SLA approaching',
  sla_breached: 'SLA breached',
  status_entered: 'Entered status',
  priority_changed: 'Priority changed',
};

export default function EscalationIndex({
  rules,
  logs,
  tickets,
  statuses,
  priorities,
  departments,
  mailboxes,
  agents,
  tags,
  preview,
}: EscalationIndexProps) {
  const [name, setName] = useState('');
  const [trigger, setTrigger] = useState<EscalationTrigger>('no_first_response');
  const [minutes, setMinutes] = useState(30);
  const [clock, setClock] = useState('any');
  const [statusId, setStatusId] = useState(statuses[0]?.id || '');
  const [priority, setPriority] = useState<TicketPriority>('normal');
  const [filtersJson, setFiltersJson] = useState('{\n  "assignee_state": "any"\n}');
  const [actionsJson, setActionsJson] = useState('[\n  {"type": "internal_note", "body": "Automatically escalated."},\n  {"type": "notify", "channel": "database"}\n]');
  const [includeClosed, setIncludeClosed] = useState(false);
  const [includeArchived, setIncludeArchived] = useState(false);
  const [jsonError, setJsonError] = useState<string | null>(null);
  const [processing, setProcessing] = useState(false);
  const [previewTickets, setPreviewTickets] = useState<Record<string, string>>({});

  const triggerConfig = () => {
    if (trigger === 'no_first_response' || trigger === 'no_activity') return { minutes };
    if (trigger === 'sla_approaching' || trigger === 'sla_breached') return { clock };
    if (trigger === 'status_entered') return { status_id: statusId };
    return { priority };
  };

  const createRule = (event: FormEvent) => {
    event.preventDefault();

    try {
      const filters = JSON.parse(filtersJson);
      const actions = JSON.parse(actionsJson);
      setJsonError(null);
      router.post('/settings/escalations', {
        name,
        trigger,
        trigger_config: triggerConfig(),
        filters,
        actions,
        include_closed: includeClosed,
        include_archived: includeArchived,
      }, {
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onSuccess: () => setName(''),
      });
    } catch {
      setJsonError('Filters and actions must be valid JSON.');
    }
  };

  const previewRule = (ruleId: string) => {
    const ticketId = previewTickets[ruleId];
    if (!ticketId) return;

    router.post(`/settings/escalations/${ruleId}/preview`, { ticket_id: ticketId }, { preserveScroll: true });
  };

  const toggleRule = (rule: EscalationRule) => {
    router.patch(`/settings/escalations/${rule.id}/active`, { is_active: !rule.is_active }, { preserveScroll: true });
  };

  const statusIcon = (status: EscalationLog['status']) => {
    if (status === 'applied') return CheckCircle2;
    if (status === 'failed') return XCircle;
    if (status === 'processing') return Clock;
    return AlertTriangle;
  };

  return (
    <SettingsLayout>
      <Head title="Escalation Rules" />

      <div className="space-y-6">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Escalation Rules</h1>
          <p className="text-muted-foreground">Apply deterministic, retry-safe actions to actionable tickets every minute.</p>
        </div>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2"><Plus className="h-5 w-5" />Create rule</CardTitle>
            <CardDescription>New and edited rules stay inactive until an administrator previews them against a ticket.</CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={createRule} className="space-y-4">
              <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="rule-name">Name</Label>
                  <Input id="rule-name" value={name} onChange={(event) => setName(event.target.value)} maxLength={255} required />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="rule-trigger">Trigger</Label>
                  <Select value={trigger} onValueChange={(value: EscalationTrigger) => setTrigger(value)}>
                    <SelectTrigger id="rule-trigger"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {Object.entries(triggerLabels).map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
              </div>

              {(trigger === 'no_first_response' || trigger === 'no_activity') && (
                <div className="space-y-2">
                  <Label htmlFor="trigger-minutes">Threshold in minutes</Label>
                  <Input id="trigger-minutes" type="number" min={1} max={525600} value={minutes} onChange={(event) => setMinutes(Number(event.target.value))} />
                </div>
              )}
              {(trigger === 'sla_approaching' || trigger === 'sla_breached') && (
                <div className="space-y-2">
                  <Label htmlFor="trigger-clock">SLA clock</Label>
                  <Select value={clock} onValueChange={setClock}>
                    <SelectTrigger id="trigger-clock"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="any">Any clock</SelectItem>
                      <SelectItem value="first_response">First response</SelectItem>
                      <SelectItem value="resolution">Resolution</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              )}
              {trigger === 'status_entered' && (
                <div className="space-y-2">
                  <Label htmlFor="trigger-status">Status</Label>
                  <Select value={statusId} onValueChange={setStatusId}>
                    <SelectTrigger id="trigger-status"><SelectValue /></SelectTrigger>
                    <SelectContent>{statuses.map((status) => <SelectItem key={status.id} value={status.id}>{status.name}</SelectItem>)}</SelectContent>
                  </Select>
                </div>
              )}
              {trigger === 'priority_changed' && (
                <div className="space-y-2">
                  <Label htmlFor="trigger-priority">Priority</Label>
                  <Select value={priority} onValueChange={(value: TicketPriority) => setPriority(value)}>
                    <SelectTrigger id="trigger-priority"><SelectValue /></SelectTrigger>
                    <SelectContent>{priorities.map((item) => <SelectItem key={item.value} value={item.value}>{item.label}</SelectItem>)}</SelectContent>
                  </Select>
                </div>
              )}

              <div className="grid gap-4 lg:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="rule-filters">Filters (JSON)</Label>
                  <Textarea id="rule-filters" value={filtersJson} onChange={(event) => setFiltersJson(event.target.value)} rows={8} className="font-mono text-xs" />
                  <p className="text-xs text-muted-foreground">
                    Allowed: status_ids, priorities, department_ids, assignee_state, mailbox_ids, tag_ids. Selected tags all must match.
                  </p>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="rule-actions">Ordered actions (JSON)</Label>
                  <Textarea id="rule-actions" value={actionsJson} onChange={(event) => setActionsJson(event.target.value)} rows={8} className="font-mono text-xs" />
                  <p className="text-xs text-muted-foreground">
                    Allowlisted types: assign, priority, status, internal_note, add_tag, remove_tag, notify. Notifications support database delivery.
                  </p>
                </div>
              </div>
              {jsonError && <p className="text-sm text-destructive">{jsonError}</p>}

              <div className="flex flex-wrap items-center justify-between gap-4">
                <div className="flex flex-wrap gap-4">
                  <div className="flex items-center gap-2">
                    <Switch id="include-closed" checked={includeClosed} onCheckedChange={setIncludeClosed} />
                    <Label htmlFor="include-closed">Include closed tickets</Label>
                  </div>
                  <div className="flex items-center gap-2">
                    <Switch id="include-archived" checked={includeArchived} onCheckedChange={setIncludeArchived} />
                    <Label htmlFor="include-archived">Include archived tickets (notification-only)</Label>
                  </div>
                </div>
                <Button type="submit" disabled={processing || !name.trim()}>{processing ? 'Creating…' : 'Create inactive rule'}</Button>
              </div>
            </form>
          </CardContent>
        </Card>

        <div className="space-y-4">
          {rules.map((rule) => (
            <Card key={rule.id}>
              <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <CardTitle className="flex items-center gap-2"><Siren className="h-4 w-4" />{rule.name}</CardTitle>
                    <CardDescription>{triggerLabels[rule.trigger]} · {rule.actions.length} ordered action{rule.actions.length === 1 ? '' : 's'} · {rule.logs_count || 0} run{rule.logs_count === 1 ? '' : 's'}</CardDescription>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge variant={rule.is_active ? 'default' : 'secondary'}>{rule.is_active ? 'Active' : 'Inactive'}</Badge>
                    <Button type="button" variant="outline" size="sm" disabled={!rule.is_active && !rule.last_previewed_at} onClick={() => toggleRule(rule)}>
                      {rule.is_active ? 'Deactivate' : 'Activate'}
                    </Button>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid gap-3 text-xs md:grid-cols-3">
                  <pre className="overflow-auto rounded bg-muted p-3">{JSON.stringify(rule.trigger_config, null, 2)}</pre>
                  <pre className="overflow-auto rounded bg-muted p-3">{JSON.stringify(rule.filters, null, 2)}</pre>
                  <pre className="overflow-auto rounded bg-muted p-3">{JSON.stringify(rule.actions, null, 2)}</pre>
                </div>
                <div className="flex flex-col gap-2 sm:flex-row">
                  <Select value={previewTickets[rule.id] || ''} onValueChange={(value) => setPreviewTickets((current) => ({ ...current, [rule.id]: value }))}>
                    <SelectTrigger className="sm:max-w-md"><SelectValue placeholder="Choose a ticket for dry run" /></SelectTrigger>
                    <SelectContent>{tickets.map((ticket) => <SelectItem key={ticket.id} value={ticket.id}>#{ticket.ticket_number} · {ticket.subject}</SelectItem>)}</SelectContent>
                  </Select>
                  <Button type="button" variant="outline" disabled={!previewTickets[rule.id]} onClick={() => previewRule(rule.id)}>
                    <Play className="mr-2 h-4 w-4" />Dry-run preview
                  </Button>
                </div>
                {preview?.rule_id === rule.id && (
                  <div className={`rounded-lg border p-4 ${preview.matched ? 'border-green-500/50 bg-green-500/5' : 'border-amber-500/50 bg-amber-500/5'}`}>
                    <p className="font-medium">Ticket #{preview.ticket_number}: {preview.matched ? 'Rule would apply' : 'Rule would not apply'}</p>
                    {preview.reasons.length > 0 && <ul className="mt-2 list-disc pl-5 text-sm text-muted-foreground">{preview.reasons.map((reason) => <li key={reason}>{reason}</li>)}</ul>}
                    <p className="mt-2 text-xs text-muted-foreground">Planned actions: {preview.actions.length}. No ticket data was changed.</p>
                  </div>
                )}
              </CardContent>
            </Card>
          ))}
          {rules.length === 0 && <Card><CardContent className="py-10 text-center text-muted-foreground">No escalation rules yet.</CardContent></Card>}
        </div>

        <Card>
          <CardHeader><CardTitle>Recent evaluator runs</CardTitle><CardDescription>Applied, skipped, and failed attempts with immutable per-action context.</CardDescription></CardHeader>
          <CardContent className="space-y-3">
            {logs.map((log) => {
              const Icon = statusIcon(log.status);
              return (
                <div key={log.id} className="rounded-lg border p-4">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2"><Icon className="h-4 w-4" /><span className="font-medium">{log.rule?.name || 'Deleted rule'}</span><span className="text-sm text-muted-foreground">#{log.ticket?.ticket_number}</span></div>
                    <Badge variant={log.status === 'failed' ? 'destructive' : log.status === 'applied' ? 'default' : 'secondary'}>{log.status}</Badge>
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">System actor · attempt {log.attempts} · window {log.trigger_window}</p>
                  {log.error && <p className="mt-2 text-sm text-destructive">{log.error}</p>}
                  {log.action_logs && log.action_logs.length > 0 && (
                    <ol className="mt-3 space-y-1 text-xs text-muted-foreground">
                      {log.action_logs.map((action) => <li key={action.id}>{action.action_order}. {action.action_type} — {action.status} by {action.actor}</li>)}
                    </ol>
                  )}
                </div>
              );
            })}
            {logs.length === 0 && <p className="py-6 text-center text-sm text-muted-foreground">No evaluator runs recorded.</p>}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Configuration references</CardTitle></CardHeader>
          <CardContent className="grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
            <div><p className="font-medium">Departments</p>{departments.map((item) => <p key={item.id}>{item.name}: {item.id}</p>)}</div>
            <div><p className="font-medium">Mailboxes</p>{mailboxes.map((item) => <p key={item.id}>{item.name}: {item.id}</p>)}</div>
            <div><p className="font-medium">Agents</p>{agents.map((item) => <p key={item.id}>{item.name}: {item.id}</p>)}</div>
            <div><p className="font-medium">Tags</p>{tags.map((item) => <p key={item.id}>{item.name}: {item.id}</p>)}</div>
          </CardContent>
        </Card>
      </div>
    </SettingsLayout>
  );
}
