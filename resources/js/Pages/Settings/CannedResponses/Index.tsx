import { Head, useForm, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { PageProps, CannedResponse } from '@/types';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Switch } from '@/Components/ui/switch';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
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
import { Plus, MoreVertical, MessageSquare, Search } from 'lucide-react';

interface CannedResponsesIndexProps extends PageProps {
    cannedResponses: CannedResponse[];
}

export default function CannedResponsesIndex({ cannedResponses }: CannedResponsesIndexProps) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingResponse, setEditingResponse] = useState<CannedResponse | null>(null);
    const [searchQuery, setSearchQuery] = useState('');

    const { data, setData, post, put, processing, errors, reset } = useForm({
        title: '',
        body: '',
        is_active: true,
        visibility: 'all_agents' as 'all_agents' | 'creator_only',
    });

    const openDialog = (response?: CannedResponse) => {
        if (response) {
            setEditingResponse(response);
            setData({
                title: response.title,
                body: response.body,
                is_active: response.is_active,
                visibility: response.visibility,
            });
        } else {
            setEditingResponse(null);
            reset();
        }
        setDialogOpen(true);
    };

    const closeDialog = () => {
        setDialogOpen(false);
        setEditingResponse(null);
        reset();
    };

    const handleSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editingResponse) {
            put(route('settings.canned-responses.update', editingResponse.id), {
                onSuccess: closeDialog,
            });
        } else {
            post(route('settings.canned-responses.store'), {
                onSuccess: closeDialog,
            });
        }
    };

    const deleteResponse = (responseId: string) => {
        if (confirm('Are you sure you want to delete this canned response?')) {
            router.delete(route('settings.canned-responses.destroy', responseId));
        }
    };

    const filteredResponses = cannedResponses.filter((response) =>
        response.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
        response.body.toLowerCase().includes(searchQuery.toLowerCase())
    );

    return (
        <SettingsLayout>
            <Head title="Canned Responses" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Canned Responses</h1>
                        <p className="text-muted-foreground">
                            Create reusable message templates for faster support
                        </p>
                    </div>
                    <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                        <DialogTrigger asChild>
                            <Button onClick={() => openDialog()}>
                                <Plus className="mr-2 h-4 w-4" />
                                Add Response
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="max-w-2xl">
                            <form onSubmit={handleSubmit}>
                                <DialogHeader>
                                    <DialogTitle>
                                        {editingResponse
                                            ? 'Edit Canned Response'
                                            : 'Create Canned Response'}
                                    </DialogTitle>
                                    <DialogDescription>
                                        Create a reusable template for common responses
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="space-y-4 py-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="title">Title</Label>
                                        <Input
                                            id="title"
                                            value={data.title}
                                            onChange={(e) => setData('title', e.target.value)}
                                            placeholder="Thank you for contacting us"
                                        />
                                        {errors.title && (
                                            <p className="text-sm text-destructive">{errors.title}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="body">Message Body</Label>
                                        <Textarea
                                            id="body"
                                            value={data.body}
                                            onChange={(e) => setData('body', e.target.value)}
                                            placeholder="Hi {{customer.name}}, thank you for reaching out..."
                                            rows={10}
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Allowed placeholders: {'{{customer.name}}'}, {'{{ticket.ticket_number}}'}, {'{{ticket.subject}}'}, {'{{department.name}}'}, {'{{assignee.name}}'}, {'{{current_date}}'}.
                                        </p>
                                        {errors.body && (
                                            <p className="text-sm text-destructive">{errors.body}</p>
                                        )}
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="response-visibility">Visibility</Label>
                                            <Select value={data.visibility} onValueChange={(value: 'all_agents' | 'creator_only') => setData('visibility', value)}>
                                                <SelectTrigger id="response-visibility"><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="all_agents">All agents</SelectItem>
                                                    <SelectItem value="creator_only">Only me</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="flex items-center gap-3 pt-7">
                                            <Switch id="response-active" checked={data.is_active} onCheckedChange={(checked) => setData('is_active', checked)} />
                                            <Label htmlFor="response-active">Available in reply picker</Label>
                                        </div>
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button type="button" variant="outline" onClick={closeDialog}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Saving...'
                                            : editingResponse
                                            ? 'Update Response'
                                            : 'Create Response'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Saved Responses</CardTitle>
                        <CardDescription>
                            {cannedResponses.length}{' '}
                            {cannedResponses.length === 1 ? 'response' : 'responses'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                placeholder="Search responses..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                className="pl-9"
                            />
                        </div>

                        {filteredResponses.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <MessageSquare className="h-12 w-12 text-muted-foreground/50" />
                                <h3 className="mt-4 text-lg font-semibold">
                                    {searchQuery
                                        ? 'No responses found'
                                        : 'No canned responses yet'}
                                </h3>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {searchQuery
                                        ? 'Try adjusting your search'
                                        : 'Get started by creating your first canned response'}
                                </p>
                                {!searchQuery && (
                                    <Button onClick={() => openDialog()} className="mt-4">
                                        <Plus className="mr-2 h-4 w-4" />
                                        Add Response
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {filteredResponses.map((response) => (
                                    <div
                                        key={response.id}
                                        className="flex items-start justify-between rounded-lg border p-4 transition-colors hover:bg-muted/50"
                                    >
                                        <div className="flex flex-1 gap-4">
                                            <MessageSquare className="h-5 w-5 text-muted-foreground" />
                                            <div className="flex-1">
                                                <h4 className="font-medium">{response.title}</h4>
                                                <div className="mt-1 flex gap-2">
                                                    <Badge variant={response.is_active ? 'default' : 'secondary'}>{response.is_active ? 'Active' : 'Inactive'}</Badge>
                                                    <Badge variant="outline">{response.visibility === 'all_agents' ? 'All agents' : 'Only creator'}</Badge>
                                                </div>
                                                <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                                    {response.body}
                                                </p>
                                                {response.creator && (
                                                    <p className="mt-2 text-xs text-muted-foreground">
                                                        Created by {response.creator.name}
                                                    </p>
                                                )}
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
                                                    onClick={() => openDialog(response)}
                                                >
                                                    Edit
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    className="text-destructive"
                                                    onClick={() => deleteResponse(response.id)}
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
