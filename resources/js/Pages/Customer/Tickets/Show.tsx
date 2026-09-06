import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import CustomerLayout from '@/Layouts/CustomerLayout';
import { CustomerTicketDetail, Customer } from '@/types';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Textarea } from '@/Components/ui/textarea';
import { Separator } from '@/Components/ui/separator';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { SafeMessageBody } from '@/Components/SafeMessageBody';
import { format } from 'date-fns';
import { Paperclip } from 'lucide-react';

interface CustomerTicketShowProps {
    ticket: CustomerTicketDetail;
    customer: Customer;
}

export default function CustomerTicketShow({ ticket, customer }: CustomerTicketShowProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        body: '',
        attachments: [] as File[],
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('customer.tickets.reply', ticket.id), {
            forceFormData: true,
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
                            const isCustomerMessage = message.sender_kind === 'customer';
                            const sender = message.sender;
                            const senderName = sender?.name || 'Unknown';
                            const senderAvatar = sender?.avatar || undefined;

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
                                        <SafeMessageBody
                                            className="prose prose-sm max-w-none"
                                            plainTextClassName="whitespace-pre-wrap text-gray-700"
                                            bodyHtml={message.body_html}
                                            bodyText={message.body_text}
                                        />
                                        {message.attachments && message.attachments.length > 0 && (
                                            <div className="mt-4 space-y-2 border-t pt-3">
                                                {message.attachments.map((attachment) => (
                                                    <div key={attachment.id} className="flex items-center gap-2 text-sm">
                                                        <Paperclip className="h-4 w-4" />
                                                        {attachment.url ? (
                                                            <a href={attachment.url} className="text-primary hover:underline">
                                                                {attachment.filename}
                                                            </a>
                                                        ) : (
                                                            <span>{attachment.filename}</span>
                                                        )}
                                                        <span className="text-xs text-gray-500">
                                                            ({Math.round(attachment.size / 1024)} KB)
                                                        </span>
                                                        {attachment.scan_status === 'pending' && (
                                                            <Badge variant="outline">Security scan pending</Badge>
                                                        )}
                                                        {attachment.scan_status === 'rejected' && (
                                                            <Badge variant="destructive">Rejected</Badge>
                                                        )}
                                                    </div>
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

                                <div className="space-y-2">
                                    <label htmlFor="customer-attachments" className="text-sm font-medium">
                                        Attachments
                                    </label>
                                    <input
                                        id="customer-attachments"
                                        type="file"
                                        multiple
                                        accept=".pdf,.txt,.csv,.png,.jpg,.jpeg,.gif,.webp,.docx,.xlsx,.pptx"
                                        onChange={(event) => setData('attachments', Array.from(event.target.files ?? []))}
                                        className="block w-full text-sm text-gray-600 file:mr-4 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium"
                                    />
                                    <p className="text-xs text-gray-500">Up to 10 files; 10 MB each and 25 MB total.</p>
                                    {errors.attachments && (
                                        <p className="text-sm text-destructive">{errors.attachments}</p>
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
