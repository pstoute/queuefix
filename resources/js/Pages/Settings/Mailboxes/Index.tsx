import { Head, Link, router } from '@inertiajs/react';
import AgentLayout from '@/Layouts/AgentLayout';
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
import { Separator } from '@/Components/ui/separator';
import { Plus, MoreVertical, Mail, Clock, CheckCircle, XCircle, RefreshCw } from 'lucide-react';
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

    return (
        <AgentLayout>
            <Head title="Mailboxes" />

            <div className="container max-w-7xl mx-auto p-6 space-y-6">
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

                <Separator />

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
                                        <TableHead>Name</TableHead>
                                        <TableHead>Email</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Department</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Last Checked</TableHead>
                                        <TableHead className="w-[50px]"></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {mailboxes.map((mailbox) => (
                                        <TableRow key={mailbox.id}>
                                            <TableCell className="font-medium">
                                                {mailbox.name}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2 text-muted-foreground">
                                                    <Mail className="h-4 w-4" />
                                                    {mailbox.email}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={getTypeBadgeVariant(mailbox.type)}>
                                                    {getTypeLabel(mailbox.type)}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {mailbox.department || (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    {mailbox.is_active ? (
                                                        <>
                                                            <CheckCircle className="h-4 w-4 text-green-500" />
                                                            <Badge variant="default">Active</Badge>
                                                        </>
                                                    ) : (
                                                        <>
                                                            <XCircle className="h-4 w-4 text-gray-400" />
                                                            <Badge variant="secondary">Inactive</Badge>
                                                        </>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                                    <Clock className="h-4 w-4" />
                                                    {mailbox.last_checked_at
                                                        ? formatDistanceToNow(
                                                              new Date(mailbox.last_checked_at),
                                                              { addSuffix: true }
                                                          )
                                                        : 'Never'}
                                                </div>
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
        </AgentLayout>
    );
}
