import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import { Archive, MoreVertical, Plus, RotateCcw, ShieldCheck, Workflow } from 'lucide-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { PageProps, TicketStatus } from '@/types';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/Components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';

interface StatusesIndexProps extends PageProps {
    statuses: TicketStatus[];
}

const emptyStatus = {
    name: '',
    slug: '',
    color: '#6b7280',
    icon: '',
    sort_order: 0,
    is_default: false,
    is_closed: false,
    is_customer_visible: true,
    pauses_sla: false,
};

export default function StatusesIndex({ statuses }: StatusesIndexProps) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingStatus, setEditingStatus] = useState<TicketStatus | null>(null);
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm(emptyStatus);

    const openDialog = (status?: TicketStatus) => {
        clearErrors();
        if (status) {
            setEditingStatus(status);
            setData({
                name: status.name,
                slug: status.slug,
                color: status.color,
                icon: status.icon || '',
                sort_order: status.sort_order,
                is_default: status.is_default,
                is_closed: status.is_closed,
                is_customer_visible: status.is_customer_visible,
                pauses_sla: status.pauses_sla,
            });
        } else {
            setEditingStatus(null);
            reset();
            setData('sort_order', statuses.filter((status) => !status.deleted_at).length * 10 + 10);
        }
        setDialogOpen(true);
    };

    const closeDialog = () => {
        setDialogOpen(false);
        setEditingStatus(null);
        reset();
        clearErrors();
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        if (editingStatus) {
            put(route('settings.statuses.update', editingStatus.id), { onSuccess: closeDialog });
        } else {
            post(route('settings.statuses.store'), { onSuccess: closeDialog });
        }
    };

    const archiveStatus = (status: TicketStatus) => {
        if (confirm(`Archive “${status.name}”?`)) {
            router.delete(route('settings.statuses.destroy', status.id));
        }
    };

    const restoreStatus = (status: TicketStatus) => {
        router.patch(route('settings.statuses.restore', status.id));
    };

    const systemStatus = editingStatus?.is_system ?? false;
    const statusError = (errors as Record<string, string | undefined>).status;

    return (
        <SettingsLayout>
            <Head title="Ticket Statuses" />

            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Ticket Statuses</h1>
                        <p className="text-muted-foreground">
                            Configure workflow labels, ordering, visibility, and closed behavior.
                        </p>
                    </div>
                    <Dialog open={dialogOpen} onOpenChange={(open) => (open ? setDialogOpen(true) : closeDialog())}>
                        <DialogTrigger asChild>
                            <Button onClick={() => openDialog()}>
                                <Plus className="mr-2 h-4 w-4" />
                                Add Status
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="sm:max-w-lg">
                            <form onSubmit={submit}>
                                <DialogHeader>
                                    <DialogTitle>{editingStatus ? 'Edit Status' : 'Create Status'}</DialogTitle>
                                    <DialogDescription>
                                        Slugs are used in ticket filters. System slugs and lifecycle behavior stay protected.
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="space-y-4 py-4">
                                    {statusError && (
                                        <p className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                                            {statusError}
                                        </p>
                                    )}
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="status-name">Name</Label>
                                            <Input
                                                id="status-name"
                                                value={data.name}
                                                onChange={(event) => setData('name', event.target.value)}
                                                placeholder="Waiting on customer"
                                            />
                                            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="status-slug">Slug</Label>
                                            <Input
                                                id="status-slug"
                                                value={data.slug}
                                                onChange={(event) => setData('slug', event.target.value)}
                                                placeholder="waiting-on-customer"
                                                disabled={systemStatus}
                                            />
                                            {errors.slug && <p className="text-sm text-destructive">{errors.slug}</p>}
                                        </div>
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <div className="space-y-2">
                                            <Label htmlFor="status-color">Color</Label>
                                            <div className="flex gap-2">
                                                <Input
                                                    id="status-color"
                                                    type="color"
                                                    value={data.color}
                                                    onChange={(event) => setData('color', event.target.value)}
                                                    className="w-14 p-1"
                                                />
                                                <Input
                                                    value={data.color}
                                                    onChange={(event) => setData('color', event.target.value)}
                                                    aria-label="Hex color"
                                                />
                                            </div>
                                            {errors.color && <p className="text-sm text-destructive">{errors.color}</p>}
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="status-icon">Icon</Label>
                                            <Input
                                                id="status-icon"
                                                value={data.icon}
                                                onChange={(event) => setData('icon', event.target.value)}
                                                placeholder="clock"
                                            />
                                            {errors.icon && <p className="text-sm text-destructive">{errors.icon}</p>}
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="status-order">Sort order</Label>
                                            <Input
                                                id="status-order"
                                                type="number"
                                                min={0}
                                                value={data.sort_order}
                                                onChange={(event) => setData('sort_order', Number(event.target.value))}
                                            />
                                            {errors.sort_order && <p className="text-sm text-destructive">{errors.sort_order}</p>}
                                        </div>
                                    </div>

                                    <div className="space-y-3 rounded-lg border p-4">
                                        <label className="flex items-center justify-between gap-4">
                                            <span>
                                                <span className="block text-sm font-medium">Default for new tickets</span>
                                                <span className="block text-xs text-muted-foreground">Exactly one status must remain the default.</span>
                                            </span>
                                            <Switch
                                                checked={data.is_default}
                                                onCheckedChange={(checked) => setData('is_default', checked)}
                                                disabled={editingStatus?.is_default}
                                            />
                                        </label>
                                        {errors.is_default && <p className="text-sm text-destructive">{errors.is_default}</p>}

                                        <label className="flex items-center justify-between gap-4">
                                            <span>
                                                <span className="block text-sm font-medium">Closed state</span>
                                                <span className="block text-xs text-muted-foreground">Sets ticket resolution and closure timestamps.</span>
                                            </span>
                                            <Switch
                                                checked={data.is_closed}
                                                onCheckedChange={(checked) => setData('is_closed', checked)}
                                                disabled={systemStatus}
                                            />
                                        </label>
                                        {errors.is_closed && <p className="text-sm text-destructive">{errors.is_closed}</p>}

                                        <label className="flex items-center justify-between gap-4">
                                            <span>
                                                <span className="block text-sm font-medium">Pause SLA clocks</span>
                                                <span className="block text-xs text-muted-foreground">Freeze incomplete SLA deadlines while tickets use this status.</span>
                                            </span>
                                            <Switch
                                                checked={data.pauses_sla}
                                                onCheckedChange={(checked) => setData('pauses_sla', checked)}
                                            />
                                        </label>
                                        {errors.pauses_sla && <p className="text-sm text-destructive">{errors.pauses_sla}</p>}

                                        <label className="flex items-center justify-between gap-4">
                                            <span>
                                                <span className="block text-sm font-medium">Customer visible</span>
                                                <span className="block text-xs text-muted-foreground">Show this status name and filter in the customer portal.</span>
                                            </span>
                                            <Switch
                                                checked={data.is_customer_visible}
                                                onCheckedChange={(checked) => setData('is_customer_visible', checked)}
                                                disabled={systemStatus}
                                            />
                                        </label>
                                        {errors.is_customer_visible && (
                                            <p className="text-sm text-destructive">{errors.is_customer_visible}</p>
                                        )}
                                    </div>
                                </div>

                                <DialogFooter>
                                    <Button type="button" variant="outline" onClick={closeDialog}>Cancel</Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Saving...' : editingStatus ? 'Update Status' : 'Create Status'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Workflow</CardTitle>
                        <CardDescription>Lower sort orders appear first in ticket selectors.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-3">
                            {statuses.map((status) => (
                                <div
                                    key={status.id}
                                    className="flex items-start justify-between gap-4 rounded-lg border p-4"
                                >
                                    <div className="flex min-w-0 items-start gap-3">
                                        <span
                                            className="mt-1 h-3 w-3 shrink-0 rounded-full"
                                            style={{ backgroundColor: status.color }}
                                            aria-hidden="true"
                                        />
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h3 className="font-medium">{status.name}</h3>
                                                {status.is_default && <Badge>Default</Badge>}
                                                {status.is_closed && <Badge variant="secondary">Closed</Badge>}
                                                {status.pauses_sla && <Badge variant="outline">Pauses SLA</Badge>}
                                                {status.is_system && (
                                                    <Badge variant="outline">
                                                        <ShieldCheck className="mr-1 h-3 w-3" /> System
                                                    </Badge>
                                                )}
                                                {status.deleted_at && <Badge variant="destructive">Archived</Badge>}
                                            </div>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {status.slug} · order {status.sort_order} · {status.tickets_count || 0} tickets
                                            </p>
                                            {!status.is_customer_visible && !status.deleted_at && (
                                                <p className="mt-1 text-xs text-muted-foreground">Internal-only customer label</p>
                                            )}
                                        </div>
                                    </div>

                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button variant="ghost" size="icon" aria-label={`Actions for ${status.name}`}>
                                                <MoreVertical className="h-4 w-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                            <DropdownMenuSeparator />
                                            {status.deleted_at ? (
                                                <DropdownMenuItem onClick={() => restoreStatus(status)}>
                                                    <RotateCcw className="mr-2 h-4 w-4" /> Restore
                                                </DropdownMenuItem>
                                            ) : (
                                                <>
                                                    <DropdownMenuItem onClick={() => openDialog(status)}>
                                                        <Workflow className="mr-2 h-4 w-4" /> Edit
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        className="text-destructive"
                                                        disabled={status.is_system || status.is_default || (status.tickets_count || 0) > 0}
                                                        onClick={() => archiveStatus(status)}
                                                    >
                                                        <Archive className="mr-2 h-4 w-4" /> Archive
                                                    </DropdownMenuItem>
                                                </>
                                            )}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </SettingsLayout>
    );
}
