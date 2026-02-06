import { Head, useForm, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import AgentLayout from '@/Layouts/AgentLayout';
import { PageProps, User } from '@/types';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
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
import { Separator } from '@/Components/ui/separator';
import { Switch } from '@/Components/ui/switch';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { UserPlus, MoreVertical, Mail, Shield, ShieldOff } from 'lucide-react';

interface UsersIndexProps extends PageProps {
    users: User[];
}

export default function UsersIndex({ users }: UsersIndexProps) {
    const [inviteDialogOpen, setInviteDialogOpen] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        role: 'agent' as 'admin' | 'agent',
    });

    const handleInvite: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('settings.users.invite'), {
            onSuccess: () => {
                reset();
                setInviteDialogOpen(false);
            },
        });
    };

    const toggleUserStatus = (userId: string, currentStatus: boolean) => {
        router.patch(route('settings.users.update', userId), {
            is_active: !currentStatus,
        });
    };

    const updateUserRole = (userId: string, role: 'admin' | 'agent') => {
        router.patch(route('settings.users.update', userId), { role });
    };

    const deleteUser = (userId: string) => {
        if (confirm('Are you sure you want to remove this user?')) {
            router.delete(route('settings.users.destroy', userId));
        }
    };

    const getInitials = (name: string) => {
        return name
            .split(' ')
            .map((n) => n[0])
            .join('')
            .toUpperCase()
            .slice(0, 2);
    };

    return (
        <AgentLayout>
            <Head title="User Management" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">User Management</h1>
                        <p className="text-muted-foreground">
                            Manage team members and their permissions
                        </p>
                    </div>
                    <Dialog open={inviteDialogOpen} onOpenChange={setInviteDialogOpen}>
                        <DialogTrigger asChild>
                            <Button>
                                <UserPlus className="mr-2 h-4 w-4" />
                                Invite User
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <form onSubmit={handleInvite}>
                                <DialogHeader>
                                    <DialogTitle>Invite Team Member</DialogTitle>
                                    <DialogDescription>
                                        Send an invitation to a new team member
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="space-y-4 py-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="name">Name</Label>
                                        <Input
                                            id="name"
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            placeholder="John Doe"
                                        />
                                        {errors.name && (
                                            <p className="text-sm text-destructive">{errors.name}</p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            value={data.email}
                                            onChange={(e) => setData('email', e.target.value)}
                                            placeholder="john@example.com"
                                        />
                                        {errors.email && (
                                            <p className="text-sm text-destructive">{errors.email}</p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="role">Role</Label>
                                        <Select
                                            value={data.role}
                                            onValueChange={(value: 'admin' | 'agent') =>
                                                setData('role', value)
                                            }
                                        >
                                            <SelectTrigger id="role">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="agent">Agent</SelectItem>
                                                <SelectItem value="admin">Admin</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        {errors.role && (
                                            <p className="text-sm text-destructive">{errors.role}</p>
                                        )}
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setInviteDialogOpen(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Sending...' : 'Send Invitation'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <Separator />

                <Card>
                    <CardHeader>
                        <CardTitle>Team Members</CardTitle>
                        <CardDescription>
                            {users.length} {users.length === 1 ? 'member' : 'members'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>User</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Role</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="w-[50px]"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {users.map((user) => (
                                    <TableRow key={user.id}>
                                        <TableCell>
                                            <div className="flex items-center gap-3">
                                                <Avatar className="h-8 w-8">
                                                    <AvatarImage src={user.avatar} />
                                                    <AvatarFallback>
                                                        {getInitials(user.name)}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <span className="font-medium">{user.name}</span>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2 text-muted-foreground">
                                                <Mail className="h-4 w-4" />
                                                {user.email}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Select
                                                value={user.role}
                                                onValueChange={(value: 'admin' | 'agent') =>
                                                    updateUserRole(user.id, value)
                                                }
                                            >
                                                <SelectTrigger className="w-32">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="agent">
                                                        <div className="flex items-center gap-2">
                                                            <ShieldOff className="h-4 w-4" />
                                                            Agent
                                                        </div>
                                                    </SelectItem>
                                                    <SelectItem value="admin">
                                                        <div className="flex items-center gap-2">
                                                            <Shield className="h-4 w-4" />
                                                            Admin
                                                        </div>
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Switch
                                                    checked={user.is_active}
                                                    onCheckedChange={() =>
                                                        toggleUserStatus(user.id, user.is_active)
                                                    }
                                                />
                                                <Badge
                                                    variant={user.is_active ? 'default' : 'secondary'}
                                                >
                                                    {user.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
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
                                                        className="text-destructive"
                                                        onClick={() => deleteUser(user.id)}
                                                    >
                                                        Remove User
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AgentLayout>
    );
}
