import { Head, Link } from '@inertiajs/react';
import { PageProps, Ticket } from '@/types';
import AgentLayout from '@/Layouts/AgentLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { cn } from '@/lib/utils';
import { formatRelativeTime } from '@/lib/hooks';
import {
  Inbox,
  Clock,
  Pause,
  CheckCircle,
  UserX,
  AlertTriangle,
} from 'lucide-react';

interface DashboardProps extends PageProps {
  stats: {
    open: number;
    pending: number;
    on_hold: number;
    resolved_today: number;
    unassigned: number;
    sla_breached: number;
  };
  recentTickets: Ticket[];
}

const statCards = [
  {
    key: 'open',
    label: 'Open Tickets',
    icon: Inbox,
    color: 'text-green-600 dark:text-green-400',
    bgColor: 'bg-green-100 dark:bg-green-950',
  },
  {
    key: 'pending',
    label: 'Pending',
    icon: Clock,
    color: 'text-amber-600 dark:text-amber-400',
    bgColor: 'bg-amber-100 dark:bg-amber-950',
  },
  {
    key: 'on_hold',
    label: 'On Hold',
    icon: Pause,
    color: 'text-gray-600 dark:text-gray-400',
    bgColor: 'bg-gray-100 dark:bg-gray-800',
  },
  {
    key: 'resolved_today',
    label: 'Resolved Today',
    icon: CheckCircle,
    color: 'text-blue-600 dark:text-blue-400',
    bgColor: 'bg-blue-100 dark:bg-blue-950',
  },
  {
    key: 'unassigned',
    label: 'Unassigned',
    icon: UserX,
    color: 'text-purple-600 dark:text-purple-400',
    bgColor: 'bg-purple-100 dark:bg-purple-950',
  },
  {
    key: 'sla_breached',
    label: 'SLA Breached',
    icon: AlertTriangle,
    color: 'text-red-600 dark:text-red-400',
    bgColor: 'bg-red-100 dark:bg-red-950',
  },
];

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

export default function Dashboard({ stats, recentTickets }: DashboardProps) {
  return (
    <AgentLayout>
      <Head title="Dashboard" />

      <div className="container max-w-7xl mx-auto p-6 space-y-8">
        {/* Page header */}
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Dashboard</h1>
          <p className="text-muted-foreground mt-1">
            Overview of your ticket queue and activity
          </p>
        </div>

        {/* Stats grid */}
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {statCards.map((stat) => {
            const Icon = stat.icon;
            const value = stats[stat.key as keyof typeof stats];
            return (
              <Card key={stat.key}>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium">
                    {stat.label}
                  </CardTitle>
                  <div className={cn('rounded-full p-2', stat.bgColor)}>
                    <Icon className={cn('h-4 w-4', stat.color)} />
                  </div>
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-bold">{value}</div>
                </CardContent>
              </Card>
            );
          })}
        </div>

        {/* Recent tickets */}
        <Card>
          <CardHeader>
            <CardTitle>Recent Tickets</CardTitle>
            <CardDescription>Your most recently updated tickets</CardDescription>
          </CardHeader>
          <CardContent>
            {recentTickets.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <Inbox className="h-12 w-12 text-muted-foreground mb-4" />
                <h3 className="text-lg font-medium">No tickets yet</h3>
                <p className="text-sm text-muted-foreground mt-1">
                  Tickets will appear here as they come in
                </p>
              </div>
            ) : (
              <div className="space-y-2">
                {recentTickets.map((ticket) => (
                  <Link
                    key={ticket.id}
                    href={`/agent/tickets/${ticket.id}`}
                    className="block rounded-lg border bg-card p-4 transition-colors hover:bg-muted/50"
                  >
                    <div className="flex items-start justify-between gap-4">
                      <div className="flex items-start gap-3 flex-1 min-w-0">
                        {/* Status dot */}
                        <div className="flex items-center pt-1">
                          <div
                            className={cn(
                              'h-2 w-2 rounded-full',
                              statusConfig[ticket.status].color
                            )}
                          />
                        </div>

                        {/* Ticket info */}
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2 mb-1">
                            <span className="text-sm font-medium text-muted-foreground">
                              #{ticket.ticket_number}
                            </span>
                            <Badge variant={priorityConfig[ticket.priority].variant}>
                              {priorityConfig[ticket.priority].label}
                            </Badge>
                          </div>
                          <p className="font-medium line-clamp-1 mb-1">
                            {ticket.subject}
                          </p>
                          <div className="flex items-center gap-3 text-sm text-muted-foreground">
                            <span>{ticket.customer?.name}</span>
                            {ticket.assignee && (
                              <>
                                <span>•</span>
                                <span>Assigned to {ticket.assignee.name}</span>
                              </>
                            )}
                          </div>
                        </div>
                      </div>

                      {/* Last activity */}
                      <div className="text-sm text-muted-foreground whitespace-nowrap">
                        {formatRelativeTime(ticket.last_activity_at)}
                      </div>
                    </div>
                  </Link>
                ))}
              </div>
            )}

            {recentTickets.length > 0 && (
              <div className="mt-4 pt-4 border-t">
                <Link
                  href="/agent/tickets"
                  className="text-sm font-medium text-primary hover:underline"
                >
                  View all tickets →
                </Link>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AgentLayout>
  );
}
