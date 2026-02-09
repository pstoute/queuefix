import { Head, Link, router } from '@inertiajs/react';
import { PageProps, PaginatedData, Ticket, User, Department, TicketStatus, TicketPriority } from '@/types';
import AgentLayout from '@/Layouts/AgentLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/Components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/Components/ui/table';
import { cn } from '@/lib/utils';
import { formatRelativeTime, formatDate } from '@/lib/hooks';
import { useState } from 'react';
import { Plus, Search, Inbox, ChevronLeft, ChevronRight } from 'lucide-react';

interface TicketsIndexProps extends PageProps {
  tickets: PaginatedData<Ticket>;
  filters: {
    status?: string;
    priority?: string;
    assignee?: string;
    department?: string;
    search?: string;
  };
  agents: User[];
  departments: Array<{ id: string; name: string }>;
  counts: {
    open: number;
    pending: number;
    unassigned: number;
  };
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

export default function TicketsIndex({ tickets, filters, agents, departments, counts }: TicketsIndexProps) {
  const [searchQuery, setSearchQuery] = useState(filters.search || '');

  const handleFilterChange = (key: string, value: string) => {
    const params = new URLSearchParams(window.location.search);
    if (value === 'all' || value === '') {
      params.delete(key);
    } else {
      params.set(key, value);
    }
    params.delete('page');
    router.get(`/agent/tickets?${params.toString()}`, {}, { preserveState: true });
  };

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    const params = new URLSearchParams(window.location.search);
    if (searchQuery) {
      params.set('search', searchQuery);
    } else {
      params.delete('search');
    }
    params.delete('page');
    router.get(`/agent/tickets?${params.toString()}`, {}, { preserveState: true });
  };

  const handlePageChange = (url: string | null) => {
    if (url) {
      router.get(url, {}, { preserveState: true });
    }
  };

  return (
    <AgentLayout>
      <Head title="Tickets" />

      <div className="container max-w-7xl mx-auto p-6 space-y-6">
        {/* Page header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">Tickets</h1>
            <p className="text-muted-foreground mt-1">
              Manage and respond to customer support tickets
            </p>
          </div>
          <Button asChild>
            <Link href="/agent/tickets/create">
              <Plus className="mr-2 h-4 w-4" />
              New Ticket
            </Link>
          </Button>
        </div>

        {/* Stats cards */}
        <div className="grid gap-4 md:grid-cols-3">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Open</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{counts.open}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Pending</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{counts.pending}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Unassigned</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{counts.unassigned}</div>
            </CardContent>
          </Card>
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="pt-6">
            <div className="flex flex-col lg:flex-row gap-4">
              {/* Search */}
              <form onSubmit={handleSearch} className="flex-1">
                <div className="relative">
                  <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                  <Input
                    placeholder="Search tickets..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="pl-9"
                  />
                </div>
              </form>

              {/* Status filter */}
              <Select
                value={filters.status || 'all'}
                onValueChange={(value) => handleFilterChange('status', value)}
              >
                <SelectTrigger className="w-full lg:w-[180px]">
                  <SelectValue placeholder="All Statuses" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Statuses</SelectItem>
                  <SelectItem value="open">Open</SelectItem>
                  <SelectItem value="pending">Pending</SelectItem>
                  <SelectItem value="on_hold">On Hold</SelectItem>
                  <SelectItem value="resolved">Resolved</SelectItem>
                  <SelectItem value="closed">Closed</SelectItem>
                </SelectContent>
              </Select>

              {/* Priority filter */}
              <Select
                value={filters.priority || 'all'}
                onValueChange={(value) => handleFilterChange('priority', value)}
              >
                <SelectTrigger className="w-full lg:w-[180px]">
                  <SelectValue placeholder="All Priorities" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Priorities</SelectItem>
                  <SelectItem value="low">Low</SelectItem>
                  <SelectItem value="normal">Normal</SelectItem>
                  <SelectItem value="high">High</SelectItem>
                  <SelectItem value="urgent">Urgent</SelectItem>
                </SelectContent>
              </Select>

              {/* Assignee filter */}
              <Select
                value={filters.assignee || 'all'}
                onValueChange={(value) => handleFilterChange('assignee', value)}
              >
                <SelectTrigger className="w-full lg:w-[180px]">
                  <SelectValue placeholder="All Assignees" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Assignees</SelectItem>
                  <SelectItem value="unassigned">Unassigned</SelectItem>
                  {agents.map((agent) => (
                    <SelectItem key={agent.id} value={agent.id}>
                      {agent.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>

              {/* Department filter */}
              {departments.length > 0 && (
                <Select
                  value={filters.department || 'all'}
                  onValueChange={(value) => handleFilterChange('department', value)}
                >
                  <SelectTrigger className="w-full lg:w-[180px]">
                    <SelectValue placeholder="All Departments" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Departments</SelectItem>
                    {departments.map((dept) => (
                      <SelectItem key={dept.id} value={dept.id}>
                        {dept.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Tickets table */}
        <Card>
          <CardContent className="p-0">
            {tickets.data.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <Inbox className="h-12 w-12 text-muted-foreground mb-4" />
                <h3 className="text-lg font-medium">No tickets found</h3>
                <p className="text-sm text-muted-foreground mt-1">
                  {Object.keys(filters).length > 0
                    ? 'Try adjusting your filters'
                    : 'Create your first ticket to get started'}
                </p>
              </div>
            ) : (
              <>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-[100px]">Ticket</TableHead>
                      <TableHead>Subject</TableHead>
                      <TableHead>Requester</TableHead>
                      <TableHead>Assignee</TableHead>
                      <TableHead>Priority</TableHead>
                      <TableHead>Tags</TableHead>
                      <TableHead>Last Activity</TableHead>
                      <TableHead>Created</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {tickets.data.map((ticket) => (
                      <TableRow
                        key={ticket.id}
                        className="cursor-pointer"
                        onClick={() => router.get(`/agent/tickets/${ticket.id}`)}
                      >
                        <TableCell className="font-medium">
                          <div className="flex items-center gap-2">
                            <div
                              className={cn(
                                'h-2 w-2 rounded-full shrink-0',
                                statusConfig[ticket.status].color
                              )}
                            />
                            <span className="text-muted-foreground">
                              #{ticket.ticket_number}
                            </span>
                          </div>
                        </TableCell>
                        <TableCell>
                          <div className="max-w-md">
                            <div className="font-medium line-clamp-1">{ticket.subject}</div>
                          </div>
                        </TableCell>
                        <TableCell>
                          <div className="text-sm">{ticket.customer?.name}</div>
                        </TableCell>
                        <TableCell>
                          {ticket.assignee ? (
                            <div className="text-sm">{ticket.assignee.name}</div>
                          ) : (
                            <span className="text-sm text-muted-foreground">Unassigned</span>
                          )}
                        </TableCell>
                        <TableCell>
                          <Badge variant={priorityConfig[ticket.priority].variant}>
                            {priorityConfig[ticket.priority].label}
                          </Badge>
                        </TableCell>
                        <TableCell>
                          {ticket.tags && ticket.tags.length > 0 ? (
                            <div className="flex flex-wrap gap-1">
                              {ticket.tags.slice(0, 2).map((tag) => (
                                <Badge
                                  key={tag.id}
                                  variant="outline"
                                  className="text-xs"
                                  style={{
                                    borderColor: tag.color,
                                    color: tag.color,
                                  }}
                                >
                                  {tag.name}
                                </Badge>
                              ))}
                              {ticket.tags.length > 2 && (
                                <Badge variant="outline" className="text-xs">
                                  +{ticket.tags.length - 2}
                                </Badge>
                              )}
                            </div>
                          ) : (
                            <span className="text-sm text-muted-foreground">—</span>
                          )}
                        </TableCell>
                        <TableCell className="text-sm text-muted-foreground">
                          {formatRelativeTime(ticket.last_activity_at)}
                        </TableCell>
                        <TableCell className="text-sm text-muted-foreground">
                          {formatDate(ticket.created_at)}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>

                {/* Pagination */}
                {tickets.last_page > 1 && (
                  <div className="flex items-center justify-between border-t px-6 py-4">
                    <div className="text-sm text-muted-foreground">
                      Showing {tickets.from} to {tickets.to} of {tickets.total} results
                    </div>
                    <div className="flex items-center gap-2">
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => handlePageChange(tickets.prev_page_url)}
                        disabled={!tickets.prev_page_url}
                      >
                        <ChevronLeft className="h-4 w-4 mr-1" />
                        Previous
                      </Button>
                      <div className="text-sm text-muted-foreground">
                        Page {tickets.current_page} of {tickets.last_page}
                      </div>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => handlePageChange(tickets.next_page_url)}
                        disabled={!tickets.next_page_url}
                      >
                        Next
                        <ChevronRight className="h-4 w-4 ml-1" />
                      </Button>
                    </div>
                  </div>
                )}
              </>
            )}
          </CardContent>
        </Card>
      </div>
    </AgentLayout>
  );
}
