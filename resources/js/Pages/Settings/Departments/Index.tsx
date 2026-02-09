import { Head, useForm, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { PageProps, Department } from '@/types';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
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
import { Plus, MoreVertical, Building2 } from 'lucide-react';

interface DepartmentsIndexProps extends PageProps {
    departments: Department[];
}

export default function DepartmentsIndex({ departments }: DepartmentsIndexProps) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingDepartment, setEditingDepartment] = useState<Department | null>(null);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        description: '',
    });

    const openDialog = (department?: Department) => {
        if (department) {
            setEditingDepartment(department);
            setData({
                name: department.name,
                description: department.description || '',
            });
        } else {
            setEditingDepartment(null);
            reset();
        }
        setDialogOpen(true);
    };

    const closeDialog = () => {
        setDialogOpen(false);
        setEditingDepartment(null);
        reset();
    };

    const handleSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editingDepartment) {
            put(route('settings.departments.update', editingDepartment.id), {
                onSuccess: closeDialog,
            });
        } else {
            post(route('settings.departments.store'), {
                onSuccess: closeDialog,
            });
        }
    };

    const deleteDepartment = (departmentId: string) => {
        if (confirm('Are you sure you want to delete this department?')) {
            router.delete(route('settings.departments.destroy', departmentId));
        }
    };

    return (
        <SettingsLayout>
            <Head title="Departments" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Departments</h1>
                        <p className="text-muted-foreground">
                            Organize tickets into departments for routing and management
                        </p>
                    </div>
                    <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                        <DialogTrigger asChild>
                            <Button onClick={() => openDialog()}>
                                <Plus className="mr-2 h-4 w-4" />
                                Add Department
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <form onSubmit={handleSubmit}>
                                <DialogHeader>
                                    <DialogTitle>
                                        {editingDepartment
                                            ? 'Edit Department'
                                            : 'Create Department'}
                                    </DialogTitle>
                                    <DialogDescription>
                                        Departments help route incoming emails to the right team
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="space-y-4 py-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="name">Name</Label>
                                        <Input
                                            id="name"
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            placeholder="e.g. Support, Billing, Technical"
                                        />
                                        {errors.name && (
                                            <p className="text-sm text-destructive">{errors.name}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="description">Description</Label>
                                        <Textarea
                                            id="description"
                                            value={data.description}
                                            onChange={(e) => setData('description', e.target.value)}
                                            placeholder="Brief description of this department"
                                            rows={3}
                                        />
                                        {errors.description && (
                                            <p className="text-sm text-destructive">{errors.description}</p>
                                        )}
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button type="button" variant="outline" onClick={closeDialog}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Saving...'
                                            : editingDepartment
                                            ? 'Update Department'
                                            : 'Create Department'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>All Departments</CardTitle>
                        <CardDescription>
                            {departments.length}{' '}
                            {departments.length === 1 ? 'department' : 'departments'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {departments.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <Building2 className="h-12 w-12 text-muted-foreground/50" />
                                <h3 className="mt-4 text-lg font-semibold">
                                    No departments yet
                                </h3>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    Create departments to organize and route tickets
                                </p>
                                <Button onClick={() => openDialog()} className="mt-4">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add Department
                                </Button>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {departments.map((department) => (
                                    <div
                                        key={department.id}
                                        className="flex items-start justify-between rounded-lg border p-4 transition-colors hover:bg-muted/50"
                                    >
                                        <div className="flex flex-1 gap-4">
                                            <Building2 className="h-5 w-5 text-muted-foreground mt-0.5" />
                                            <div className="flex-1">
                                                <h4 className="font-medium">{department.name}</h4>
                                                {department.description && (
                                                    <p className="mt-1 text-sm text-muted-foreground">
                                                        {department.description}
                                                    </p>
                                                )}
                                                <div className="mt-2 flex gap-4 text-xs text-muted-foreground">
                                                    {department.tickets_count !== undefined && (
                                                        <span>{department.tickets_count} tickets</span>
                                                    )}
                                                    {department.mailboxes_count !== undefined && (
                                                        <span>{department.mailboxes_count} mailboxes</span>
                                                    )}
                                                    {department.aliases_count !== undefined && (
                                                        <span>{department.aliases_count} aliases</span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
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
                                                    onClick={() => openDialog(department)}
                                                >
                                                    Edit
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    className="text-destructive"
                                                    onClick={() => deleteDepartment(department.id)}
                                                >
                                                    Delete
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </SettingsLayout>
    );
}
