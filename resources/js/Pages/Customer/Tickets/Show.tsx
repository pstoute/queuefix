import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import CustomerLayout from '@/Layouts/CustomerLayout';
import { Ticket, Customer, Message } from '@/types';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Textarea } from '@/Components/ui/textarea';
import { Separator } from '@/Components/ui/separator';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { format } from 'date-fns';

interface CustomerTicketShowProps {
    ticket: Ticket;
    customer: Customer;
}

export default function CustomerTicketShow({ ticket, customer }: CustomerTicketShowProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        body: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('customer.tickets.reply', ticket.id), {
            onSuccess: () => reset(),
        });
    };

    const getStatusBadgeVariant = (status: string) => {
        const variants: Record<string, 'default' | 'secondary' | 'outline' | 'destructive'> = {
            open: 'default',
            pending: 'outline',
            on_hold: 'secondary',
            resolved: 'secondary',
            closed: 'secondary',
        };
        return variants[status] || 'default';
    };

    const getStatusLabel = (status: string) => {
        const labels: Record<string, string> = {
            open: 'Open',
            pending: 'Pending',
            on_hold: 'On Hold',
            resolved: 'Resolved',
            closed: 'Closed',
        };
        return labels[status] || status;
    };

    const getInitials = (name: string) => {
        return name
            .split(' ')
            .map((n) => n[0])
            .join('')
            .toUpperCase()
            .slice(0, 2);
    };

    // Filter out internal notes - customers should only see replies
    const visibleMessages = ticket.messages?.filter((m) => m.type === 'reply') || [];

    return (
        <CustomerLayout customer={customer}>
            <Head title={`Ticket #${ticket.ticket_number}`} />

            <div className="space-y-6">
                {/* Ticket Header */}
                <div>
                    <div className="flex items-start justify-between">
                        <div>
                            <h1 className="text-3xl font-bold text-gray-900">{ticket.subject}</h1>
                            <p className="mt-1 text-sm text-gray-600">
                                Ticket #{ticket.ticket_number}
                            </p>
                        </div>
                        <Badge variant={getStatusBadgeVariant(ticket.status)}>
                            {getStatusLabel(ticket.status)}
                        </Badge>
                    </div>
                </div>

                <Separator />

                {/* Messages */}
                <div className="space-y-4">
                    {visibleMessages.length === 0 ? (
                        <Card>
                            <CardContent className="py-8 text-center text-gray-600">
                                No messages yet
                            </CardContent>
                        </Card>
                    ) : (
                        visibleMessages.map((message) => {
                            const isCustomerMessage =
                                message.sender_type === 'App\\Models\\Customer';
                            const sender = message.sender as Customer | any;
                            const senderName = sender?.name || 'Unknown';
                            const senderAvatar = sender?.avatar;

                            return (
                                <Card key={message.id}>
                                    <CardHeader>
                                        <div className="flex items-start gap-3">
                                            <Avatar>
                                                <AvatarImage src={senderAvatar} />
                                                <AvatarFallback>
                                                    {getInitials(senderName)}
                                                </AvatarFallback>
                                            </Avatar>
                                            <div className="flex-1">
                                                <div className="flex items-center justify-between">
                                                    <div>
                                                        <CardTitle className="text-base">
                                                            {senderName}
                                                            {isCustomerMessage && ' (You)'}
                                                        </CardTitle>
                                                        <p className="text-xs text-gray-500">
                                                            {format(
                                                                new Date(message.created_at),
                                                                'MMM d, yyyy at h:mm a'
                                                            )}
                                                        </p>
                                                    </div>
                                                    {!isCustomerMessage && (
                                                        <Badge variant="outline">Support</Badge>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        {message.body_html ? (
                                            <div
                                                className="prose prose-sm max-w-none"
                                                dangerouslySetInnerHTML={{
                                                    __html: message.body_html,
                                                }}
                                            />
                                        ) : (
                                            <div className="whitespace-pre-wrap text-gray-700">
                                                {message.body_text}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            );
                        })
                    )}
                </div>

                {/* Reply Form */}
                {ticket.status !== 'closed' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Reply</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="space-y-4">
                                <div className="space-y-2">
                                    <Textarea
                                        value={data.body}
                                        onChange={(e) => setData('body', e.target.value)}
                                        placeholder="Write your reply..."
                                        rows={6}
                                        className="resize-none"
                                    />
                                    {errors.body && (
                                        <p className="text-sm text-destructive">{errors.body}</p>
                                    )}
                                </div>

                                <div className="flex justify-end">
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Sending...' : 'Send Reply'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {ticket.status === 'closed' && (
                    <Card>
                        <CardContent className="py-6 text-center">
                            <p className="text-sm text-gray-600">
                                This ticket has been closed. If you need further assistance, please
                                create a new ticket.
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>
        </CustomerLayout>
    );
}
