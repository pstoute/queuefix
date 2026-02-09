import { Head, useForm, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { PageProps, SlaPolicy, TicketPriority } from '@/types';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
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
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Switch } from '@/Components/ui/switch';
import { Plus, MoreVertical, Clock, AlertCircle } from 'lucide-react';

interface SlaIndexProps extends PageProps {
    policies: SlaPolicy[];
    priorities: Array<{ value: TicketPriority; label: string }>;
}

export default function SlaIndex({ policies, priorities }: SlaIndexProps) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingPolicy, setEditingPolicy] = useState<SlaPolicy | null>(null);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        priority: 'normal' as TicketPriority,
        first_response_hours: 1,
        resolution_hours: 24,
        is_active: true,
    });

    const openDialog = (policy?: SlaPolicy) => {
        if (policy) {
            setEditingPolicy(policy);
            setData({
                name: policy.name,
                priority: policy.priority,
                first_response_hours: policy.first_response_hours,
                resolution_hours: policy.resolution_hours,
                is_active: policy.is_active,
            });
        } else {
            setEditingPolicy(null);
            reset();
        }
        setDialogOpen(true);
    };

    const closeDialog = () => {
        setDialogOpen(false);
        setEditingPolicy(null);
        reset();
    };

    const handleSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editingPolicy) {
            put(route('settings.sla.update', editingPolicy.id), {
                onSuccess: closeDialog,
            });
        } else {
            post(route('settings.sla.store'), {
                onSuccess: closeDialog,
            });
        }
    };

    const deletePolicy = (policyId: string) => {
        if (confirm('Are you sure you want to delete this SLA policy?')) {
            router.delete(route('settings.sla.destroy', policyId));
        }
    };

    const togglePolicyStatus = (policyId: string, currentStatus: boolean) => {
        router.patch(route('settings.sla.update', policyId), {
            is_active: !currentStatus,
        });
    };

    const getPriorityBadgeVariant = (priority: TicketPriority) => {
        const variants: Record<TicketPriority, 'default' | 'secondary' | 'destructive' | 'outline'> = {
            low: 'secondary',
            normal: 'default',
            high: 'outline',
            urgent: 'destructive',
        };
        return variants[priority];
    };

    const groupedPolicies = priorities.map((priority) => ({
        priority: priority.value,
        label: priority.label,
        policies: policies.filter((p) => p.priority === priority.value),
    }));

    return (
        <SettingsLayout>
            <Head title="SLA Policies" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">SLA Policies</h1>
                        <p className="text-muted-foreground">
                            Define service level agreements for different ticket priorities
                        </p>
                    </div>
                    <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                        <DialogTrigger asChild>
                            <Button onClick={() => openDialog()}>
                                <Plus className="mr-2 h-4 w-4" />
                                Add SLA Policy
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <form onSubmit={handleSubmit}>
                                <DialogHeader>
                                    <DialogTitle>
                                        {editingPolicy ? 'Edit SLA Policy' : 'Create SLA Policy'}
                                    </DialogTitle>
                                    <DialogDescription>
                                        Define response and resolution time targets
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="space-y-4 py-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="name">Policy Name</Label>
                                        <Input
                                            id="name"
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            placeholder="Standard SLA"
                                        />
                                        {errors.name && (
                                            <p className="text-sm text-destructive">{errors.name}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="priority">Priority</Label>
                                        <Select
                                            value={data.priority}
                                            onValueChange={(value: TicketPriority) =>
                                                setData('priority', value)
                                            }
                                        >
                                            <SelectTrigger id="priority">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {priorities.map((priority) => (
                                                    <SelectItem
                                                        key={priority.value}
                                                        value={priority.value}
                                                    >
                                                        {priority.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.priority && (
                                            <p className="text-sm text-destructive">
                                                {errors.priority}
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="first_response_hours">
                                            First Response Time (hours)
                                        </Label>
                                        <Input
                                            id="first_response_hours"
                                            type="number"
                                            min="0.5"
                                            step="0.5"
                                            value={data.first_response_hours}
                                            onChange={(e) =>
                                                setData(
                                                    'first_response_hours',
                                                    parseFloat(e.target.value)
                                                )
                                            }
                                        />
                                        {errors.first_response_hours && (
                                            <p className="text-sm text-destructive">
                                                {errors.first_response_hours}
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="resolution_hours">
                                            Resolution Time (hours)
                                        </Label>
                                        <Input
                                            id="resolution_hours"
                                            type="number"
                                            min="1"
                                            step="1"
                                            value={data.resolution_hours}
                                            onChange={(e) =>
                                                setData('resolution_hours', parseFloat(e.target.value))
                                            }
                                        />
                                        {errors.resolution_hours && (
                                            <p className="text-sm text-destructive">
                                                {errors.resolution_hours}
                                            </p>
                                        )}
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <Switch
                                            id="is_active"
                                            checked={data.is_active}
                                            onCheckedChange={(checked) =>
                                                setData('is_active', checked)
                                            }
                                        />
                                        <Label htmlFor="is_active">Active</Label>
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button type="button" variant="outline" onClick={closeDialog}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Saving...'
                                            : editingPolicy
                                            ? 'Update Policy'
                                            : 'Create Policy'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <div className="space-y-6">
                    {groupedPolicies.map((group) => (
                        <Card key={group.priority}>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle className="flex items-center gap-2">
                                            <Badge variant={getPriorityBadgeVariant(group.priority)}>
                                                {group.label}
                                            </Badge>
                                            Priority
                                        </CardTitle>
                                        <CardDescription>
                                            {group.policies.length}{' '}
                                            {group.policies.length === 1 ? 'policy' : 'policies'}
                                        </CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {group.policies.length === 0 ? (
                                    <div className="flex flex-col items-center justify-center py-8 text-center">
                                        <AlertCircle className="h-8 w-8 text-muted-foreground/50" />
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            No SLA policies for this priority level
                                        </p>
                                    </div>
                                ) : (
                                    <div className="space-y-3">
                                        {group.policies.map((policy) => (
                                            <div
                                                key={policy.id}
                                                className="flex items-center justify-between rounded-lg border p-4"
                                            >
                                                <div className="flex items-center gap-4">
                                                    <Clock className="h-5 w-5 text-muted-foreground" />
                                                    <div>
                                                        <div className="font-medium">{policy.name}</div>
                                                        <div className="text-sm text-muted-foreground">
                                                            First Response: {policy.first_response_hours}h
                                                            | Resolution: {policy.resolution_hours}h
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <Switch
                                                        checked={policy.is_active}
                                                        onCheckedChange={() =>
                                                            togglePolicyStatus(
                                                                policy.id,
                                                                policy.is_active
                                                            )
                                                        }
                                                    />
                                                    <Badge
                                                        variant={
                                                            policy.is_active
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {policy.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button variant="ghost" size="icon">
                                                                <MoreVertical className="h-4 w-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuLabel>
                                                                Actions
                                                            </DropdownMenuLabel>
                                                            <DropdownMenuSeparator />
                                                            <DropdownMenuItem
                                                                onClick={() => openDialog(policy)}
                                                            >
                                                                Edit
                                                            </DropdownMenuItem>
                                                            <DropdownMenuSeparator />
                                                            <DropdownMenuItem
                                                                className="text-destructive"
                                                                onClick={() => deletePolicy(policy.id)}
                                                            >
                                                                Delete
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </SettingsLayout>
    );
}
