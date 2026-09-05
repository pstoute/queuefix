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
    const ratingForm = useForm({
        rating: 0,
        feedback: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('customer.tickets.reply', ticket.id), {
            onSuccess: () => reset(),
        });
    };

    const submitRating: FormEventHandler = (e) => {
        e.preventDefault();
        ratingForm.post(route('customer.tickets.rating.store', ticket.id), {
            preserveScroll: true,
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
                        <CardHeader>
                            <CardTitle>Your support experience</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {ticket.rating ? (
                                <div className="rounded-md bg-green-50 p-4 text-green-900">
                                    <p className="font-medium">Thank you for your feedback.</p>
                                    <p className="mt-1 text-sm">You rated this support experience {ticket.rating.rating} out of 5.</p>
                                    {ticket.rating.feedback && (
                                        <p className="mt-3 whitespace-pre-wrap text-sm">{ticket.rating.feedback}</p>
                                    )}
                                </div>
                            ) : (
                                <form onSubmit={submitRating} className="space-y-4">
                                    <fieldset className="space-y-2">
                                        <legend className="text-sm font-medium">How would you rate the support you received?</legend>
                                        <div className="flex flex-wrap gap-2">
                                            {[1, 2, 3, 4, 5].map((score) => (
                                                <Button
                                                    key={score}
                                                    type="button"
                                                    variant={ratingForm.data.rating === score ? 'default' : 'outline'}
                                                    onClick={() => ratingForm.setData('rating', score)}
                                                    aria-pressed={ratingForm.data.rating === score}
                                                    aria-label={`${score} out of 5`}
                                                >
                                                    {score}
                                                </Button>
                                            ))}
                                        </div>
                                        {ratingForm.errors.rating && (
                                            <p className="text-sm text-destructive">{ratingForm.errors.rating}</p>
                                        )}
                                    </fieldset>

                                    <div className="space-y-2">
                                        <label htmlFor="rating-feedback" className="text-sm font-medium">
                                            Additional feedback (optional)
                                        </label>
                                        <Textarea
                                            id="rating-feedback"
                                            value={ratingForm.data.feedback}
                                            onChange={(event) => ratingForm.setData('feedback', event.target.value)}
                                            maxLength={5000}
                                            rows={4}
                                            className="resize-none"
                                        />
                                        {ratingForm.errors.feedback && (
                                            <p className="text-sm text-destructive">{ratingForm.errors.feedback}</p>
                                        )}
                                    </div>

                                    <Button
                                        type="submit"
                                        disabled={ratingForm.processing || ratingForm.data.rating === 0}
                                    >
                                        {ratingForm.processing ? 'Submitting...' : 'Submit rating'}
                                    </Button>
                                </form>
                            )}

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
