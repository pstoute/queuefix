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
        cc_recipient_ids: ticket.cc_recipients?.map((recipient) => recipient.id) || [] as string[],
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('customer.tickets.reply', ticket.id), {
            onSuccess: () => reset(),
        });
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
                                        {message.cc_recipients && message.cc_recipients.length > 0 && (
                                            <div className="mt-4 flex flex-wrap items-center gap-1 border-t pt-3 text-xs text-gray-600">
                                                <span>CC:</span>
                                                {message.cc_recipients.map((recipient) => (
                                                    <Badge key={recipient.id} variant="outline">
                                                        {recipient.display_name || recipient.email}
                                                    </Badge>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            );
                        })
                    )}
                </div>

                {/* Reply Form */}
                {!ticket.customer_status?.is_closed && (
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

                                {ticket.cc_recipients && ticket.cc_recipients.length > 0 && (
                                    <div className="space-y-2 rounded-md border p-3">
                                        <p className="text-sm font-medium">Approved CC recipients</p>
                                        <p className="text-xs text-gray-600">
                                            You may include only recipients already approved for this ticket.
                                        </p>
                                        {ticket.cc_recipients.map((recipient) => (
                                            <label key={recipient.id} className="flex items-center gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    checked={data.cc_recipient_ids.includes(recipient.id)}
                                                    onChange={(event) => setData(
                                                        'cc_recipient_ids',
                                                        event.target.checked
                                                            ? [...data.cc_recipient_ids, recipient.id]
                                                            : data.cc_recipient_ids.filter((id) => id !== recipient.id)
                                                    )}
                                                />
                                                {recipient.display_name || recipient.email}
                                            </label>
                                        ))}
                                        {errors.cc_recipient_ids && (
                                            <p className="text-sm text-destructive">{errors.cc_recipient_ids}</p>
                                        )}
                                    </div>
                                )}

                                <div className="flex justify-end">
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Sending...' : 'Send Reply'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {ticket.customer_status?.is_closed && (
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
