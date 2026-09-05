import { Head, Link, router } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { PageProps, Mailbox } from '@/types';
import { Button } from '@/Components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Plus, MoreVertical, Mail, Clock, CheckCircle, XCircle, RefreshCw, Play, AlertTriangle, LoaderCircle } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';

interface MailboxesIndexProps extends PageProps {
    mailboxes: Mailbox[];
}

export default function MailboxesIndex({ mailboxes }: MailboxesIndexProps) {
    const testConnection = (mailboxId: string) => {
        router.post(route('settings.mailboxes.test', mailboxId), {}, {
            preserveScroll: true,
        });
    };

    const fetchNow = (mailboxId: string) => {
        router.post(route('settings.mailboxes.fetch', mailboxId), {}, {
            preserveScroll: true,
        });
    };

    const deleteMailbox = (mailboxId: string) => {
        if (confirm('Are you sure you want to delete this mailbox? This action cannot be undone.')) {
            router.delete(route('settings.mailboxes.destroy', mailboxId));
        }
    };

    const getTypeLabel = (type: string) => {
        const labels: Record<string, string> = {
            imap: 'IMAP',
            gmail: 'Gmail',
            microsoft: 'Microsoft 365',
        };
        return labels[type] || type;
    };

    const getTypeBadgeVariant = (type: string) => {
        const variants: Record<string, 'default' | 'secondary' | 'outline'> = {
            imap: 'outline',
            gmail: 'default',
            microsoft: 'secondary',
        };
        return variants[type] || 'outline';
    };

    const healthLabel = (status: Mailbox['health_status']) => ({
        inactive: 'Inactive',
        authentication_required: 'Authentication required',
        fetch_failing: 'Fetch failing',
        processing_failing: 'Processing failing',
        fetching: 'Fetching',
        queued: 'Queued',
        never_fetched: 'Never fetched',
        stale: 'Stale',
        healthy: 'Healthy',
    }[status]);

    const healthVariant = (status: Mailbox['health_status']): 'default' | 'secondary' | 'destructive' | 'outline' => {
        if (status === 'healthy') return 'default';
        if (status === 'authentication_required' || status === 'fetch_failing' || status === 'processing_failing') return 'destructive';
        if (status === 'fetching' || status === 'queued') return 'secondary';
        return 'outline';
    };

    const relativeTime = (value?: string | null) => value
        ? formatDistanceToNow(new Date(value), { addSuffix: true })
        : 'Never';

    return (
        <SettingsLayout>
            <Head title="Mailboxes" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Mailboxes</h1>
                        <p className="text-muted-foreground">
                            Manage email accounts for receiving and sending tickets
                        </p>
                    </div>
                    <Link href={route('settings.mailboxes.create')}>
                        <Button>
                            <Plus className="mr-2 h-4 w-4" />
                            Add Mailbox
                        </Button>
                    </Link>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Connected Mailboxes</CardTitle>
                        <CardDescription>
                            {mailboxes.length} {mailboxes.length === 1 ? 'mailbox' : 'mailboxes'} configured
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {mailboxes.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <Mail className="h-12 w-12 text-muted-foreground/50" />
                                <h3 className="mt-4 text-lg font-semibold">No mailboxes configured</h3>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    Get started by adding your first mailbox
                                </p>
                                <Link href={route('settings.mailboxes.create')} className="mt-4">
                                    <Button>
                                        <Plus className="mr-2 h-4 w-4" />
                                        Add Mailbox
                                    </Button>
                                </Link>
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Mailbox</TableHead>
                                        <TableHead>Provider & interval</TableHead>
                                        <TableHead>Ingestion health</TableHead>
                                        <TableHead>Last success</TableHead>
                                        <TableHead>Queue & backlog</TableHead>
                                        <TableHead className="w-[50px]"></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {mailboxes.map((mailbox) => (
                                        <TableRow key={mailbox.id}>
                                            <TableCell>
                                                <p className="font-medium">{mailbox.name}</p>
                                                <div className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                                                    <Mail className="h-3.5 w-3.5" />{mailbox.email}
                                                </div>
                                                <p className="mt-1 text-xs text-muted-foreground">{mailbox.department?.name || 'No department'}</p>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={getTypeBadgeVariant(mailbox.type)}>
                                                    {getTypeLabel(mailbox.type)}
                                                </Badge>
                                                <p className="mt-2 text-xs text-muted-foreground">Every {mailbox.polling_interval} min</p>
                                                {mailbox.provider_cursor && (
                                                    <p className="mt-1 max-w-40 truncate font-mono text-[11px] text-muted-foreground" title={mailbox.provider_cursor}>
                                                        Cursor {mailbox.provider_cursor}
                                                    </p>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    {mailbox.health_status === 'healthy' ? <CheckCircle className="h-4 w-4 text-green-500" /> :
                                                        mailbox.health_status === 'fetching' || mailbox.health_status === 'queued' ? <LoaderCircle className="h-4 w-4 animate-spin text-blue-500" /> :
                                                            mailbox.health_status === 'inactive' ? <XCircle className="h-4 w-4 text-gray-400" /> : <AlertTriangle className="h-4 w-4 text-amber-500" />}
                                                    <Badge variant={healthVariant(mailbox.health_status)}>{healthLabel(mailbox.health_status)}</Badge>
                                                </div>
                                                {mailbox.last_fetch_error_message && <p className="mt-2 max-w-64 text-xs text-destructive">{mailbox.last_fetch_error_message}</p>}
                                                {mailbox.last_fetch_error_code && <p className="mt-1 font-mono text-[11px] text-muted-foreground">{mailbox.last_fetch_error_code}</p>}
                                                {!mailbox.last_fetch_error_message && mailbox.last_processing_error_message && <p className="mt-2 max-w-64 text-xs text-destructive">{mailbox.last_processing_error_message}</p>}
                                                {!mailbox.last_fetch_error_code && mailbox.last_processing_error_code && <p className="mt-1 font-mono text-[11px] text-muted-foreground">{mailbox.last_processing_error_code}</p>}
                                                {mailbox.next_fetch_at && mailbox.consecutive_fetch_failures > 0 && <p className="mt-1 text-xs text-muted-foreground">Retry {relativeTime(mailbox.next_fetch_at)}</p>}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                                    <Clock className="h-4 w-4" />{relativeTime(mailbox.last_fetch_succeeded_at)}
                                                </div>
                                                <p className="mt-1 text-xs text-muted-foreground">Attempt: {relativeTime(mailbox.last_fetch_attempted_at)}</p>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline" className="capitalize">{mailbox.queue.status}</Badge>
                                                <p className="mt-2 text-xs text-muted-foreground">{mailbox.queue.pending_messages} message{mailbox.queue.pending_messages === 1 ? '' : 's'} pending</p>
                                                {mailbox.queue.processing_failures > 0 && (
                                                    <p className="mt-1 text-xs text-destructive">{mailbox.queue.processing_failures} processing failure{mailbox.queue.processing_failures === 1 ? '' : 's'}</p>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button variant="ghost" size="icon">
                                                            <MoreVertical className="h-4 w-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            disabled={!mailbox.is_active || mailbox.queue.status !== 'idle'}
                                                            onClick={() => fetchNow(mailbox.id)}
                                                        >
                                                            <Play className="mr-2 h-4 w-4" />
                                                            Fetch now
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            onClick={() => testConnection(mailbox.id)}
                                                        >
                                                            <RefreshCw className="mr-2 h-4 w-4" />
                                                            Test Connection
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem asChild>
                                                            <Link
                                                                href={route(
                                                                    'settings.mailboxes.edit',
                                                                    mailbox.id
                                                                )}
                                                            >
                                                                Edit
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            className="text-destructive"
                                                            onClick={() => deleteMailbox(mailbox.id)}
                                                        >
                                                            Delete
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </SettingsLayout>
    );
}
