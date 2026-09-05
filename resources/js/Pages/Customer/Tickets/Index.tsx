import { Head, Link, router } from '@inertiajs/react';
import CustomerLayout from '@/Layouts/CustomerLayout';
import { PaginatedData, Ticket, Customer, TicketStatus } from '@/types';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Separator } from '@/Components/ui/separator';
import { Plus, MessageSquare, Clock } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';

interface CustomerTicketsIndexProps {
    tickets: PaginatedData<Ticket>;
    customer: Customer;
    statuses: TicketStatus[];
    filters: { status?: string };
}

export default function CustomerTicketsIndex({ tickets, customer, statuses, filters }: CustomerTicketsIndexProps) {
    return (
        <CustomerLayout customer={customer}>
            <Head title="My Tickets" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">My Tickets</h1>
                        <p className="mt-1 text-sm text-gray-600">
                            View and manage your support requests
                        </p>
                    </div>
                    <Link href={route('customer.tickets.create')}>
                        <Button>
                            <Plus className="mr-2 h-4 w-4" />
                            New Ticket
                        </Button>
                    </Link>
                </div>

                <Separator />

                <div className="flex justify-end">
                    <Select
                        value={filters.status || 'all'}
                        onValueChange={(status) => router.get(
                            route('customer.tickets.index'),
                            status === 'all' ? {} : { status },
                            { preserveState: true }
                        )}
                    >
                        <SelectTrigger className="w-full sm:w-56">
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            {statuses.map((status) => (
                                <SelectItem key={status.id} value={status.slug}>{status.name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {tickets.data.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12">
                            <MessageSquare className="h-12 w-12 text-gray-400" />
                            <h3 className="mt-4 text-lg font-semibold text-gray-900">
                                No tickets yet
                            </h3>
                            <p className="mt-2 text-sm text-gray-600">
                                Get started by creating your first support ticket
                            </p>
                            <Link href={route('customer.tickets.create')} className="mt-4">
                                <Button>
                                    <Plus className="mr-2 h-4 w-4" />
                                    New Ticket
                                </Button>
                            </Link>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-4">
                        {tickets.data.map((ticket) => (
                            <Link
                                key={ticket.id}
                                href={route('customer.tickets.show', ticket.id)}
                            >
                                <Card className="transition-shadow hover:shadow-md">
                                    <CardHeader>
                                        <div className="flex items-start justify-between">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-3">
                                                    <CardTitle className="text-lg">
                                                        {ticket.subject}
                                                    </CardTitle>
                                                    <Badge
                                                        variant="outline"
                                                        style={{
                                                            borderColor: ticket.customer_status?.color,
                                                            color: ticket.customer_status?.color,
                                                        }}
                                                    >
                                                        {ticket.customer_status?.name || 'In Progress'}
                                                    </Badge>
                                                </div>
                                                <CardDescription className="mt-1">
                                                    Ticket #{ticket.ticket_number}
                                                </CardDescription>
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="flex items-center gap-6 text-sm text-gray-600">
                                            <div className="flex items-center gap-2">
                                                <Clock className="h-4 w-4" />
                                                Last updated{' '}
                                                {formatDistanceToNow(
                                                    new Date(ticket.last_activity_at),
                                                    { addSuffix: true }
                                                )}
                                            </div>
                                            {ticket.messages && ticket.messages.length > 0 && (
                                                <div className="flex items-center gap-2">
                                                    <MessageSquare className="h-4 w-4" />
                                                    {ticket.messages.length}{' '}
                                                    {ticket.messages.length === 1
                                                        ? 'message'
                                                        : 'messages'}
                                                </div>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            </Link>
                        ))}
                    </div>
                )}

                {tickets.last_page > 1 && (
                    <div className="flex items-center justify-center gap-2">
                        {tickets.prev_page_url && (
                            <Link href={tickets.prev_page_url}>
                                <Button variant="outline">Previous</Button>
                            </Link>
                        )}
                        <span className="text-sm text-gray-600">
                            Page {tickets.current_page} of {tickets.last_page}
                        </span>
                        {tickets.next_page_url && (
                            <Link href={tickets.next_page_url}>
                                <Button variant="outline">Next</Button>
                            </Link>
                        )}
                    </div>
                )}
            </div>
        </CustomerLayout>
    );
}
