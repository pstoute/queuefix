import { Head, useForm, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import AgentLayout from '@/Layouts/AgentLayout';
import { PageProps, Tag } from '@/types';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
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
import { Separator } from '@/Components/ui/separator';
import { Plus, MoreVertical, TagIcon, Search } from 'lucide-react';

interface TagsIndexProps extends PageProps {
    tags: Tag[];
}

export default function TagsIndex({ tags }: TagsIndexProps) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        color: '#3B82F6',
    });

    const closeDialog = () => {
        setDialogOpen(false);
        reset();
    };

    const handleSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('agent.tags.store'), {
            onSuccess: closeDialog,
        });
    };

    const deleteTag = (tagId: string) => {
        if (confirm('Are you sure you want to delete this tag?')) {
            router.delete(route('agent.tags.destroy', tagId));
        }
    };

    const filteredTags = tags.filter((tag) =>
        tag.name.toLowerCase().includes(searchQuery.toLowerCase())
    );

    return (
        <AgentLayout>
            <Head title="Tags" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Tags</h1>
                        <p className="text-muted-foreground">
                            Manage tags to organize and categorize tickets
                        </p>
                    </div>
                    <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                        <DialogTrigger asChild>
                            <Button onClick={() => setDialogOpen(true)}>
                                <Plus className="mr-2 h-4 w-4" />
                                Add Tag
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <form onSubmit={handleSubmit}>
                                <DialogHeader>
                                    <DialogTitle>Create Tag</DialogTitle>
                                    <DialogDescription>
                                        Add a new tag to organize your tickets
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="space-y-4 py-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="name">Name</Label>
                                        <Input
                                            id="name"
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            placeholder="e.g. Bug, Feature Request"
                                        />
                                        {errors.name && (
                                            <p className="text-sm text-destructive">{errors.name}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="color">Color</Label>
                                        <div className="flex items-center gap-3">
                                            <input
                                                type="color"
                                                id="color"
                                                value={data.color}
                                                onChange={(e) => setData('color', e.target.value)}
                                                className="h-10 w-10 cursor-pointer rounded border p-1"
                                            />
                                            <Input
                                                value={data.color}
                                                onChange={(e) => setData('color', e.target.value)}
                                                placeholder="#3B82F6"
                                                className="flex-1"
                                            />
                                        </div>
                                        {errors.color && (
                                            <p className="text-sm text-destructive">{errors.color}</p>
                                        )}
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button type="button" variant="outline" onClick={closeDialog}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Creating...' : 'Create Tag'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <Separator />

                <Card>
                    <CardHeader>
                        <CardTitle>All Tags</CardTitle>
                        <CardDescription>
                            {tags.length} {tags.length === 1 ? 'tag' : 'tags'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                placeholder="Search tags..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                className="pl-9"
                            />
                        </div>

                        {filteredTags.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <TagIcon className="h-12 w-12 text-muted-foreground/50" />
                                <h3 className="mt-4 text-lg font-semibold">
                                    {searchQuery ? 'No tags found' : 'No tags yet'}
                                </h3>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {searchQuery
                                        ? 'Try adjusting your search'
                                        : 'Get started by creating your first tag'}
                                </p>
                                {!searchQuery && (
                                    <Button onClick={() => setDialogOpen(true)} className="mt-4">
                                        <Plus className="mr-2 h-4 w-4" />
                                        Add Tag
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {filteredTags.map((tag) => (
                                    <div
                                        key={tag.id}
                                        className="flex items-center justify-between rounded-lg border p-4 transition-colors hover:bg-muted/50"
                                    >
                                        <div className="flex items-center gap-3">
                                            <div
                                                className="h-4 w-4 rounded-full"
                                                style={{ backgroundColor: tag.color }}
                                            />
                                            <span className="font-medium">{tag.name}</span>
                                            <span className="text-xs text-muted-foreground">
                                                {tag.color}
                                            </span>
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
                                                    className="text-destructive"
                                                    onClick={() => deleteTag(tag.id)}
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
        </AgentLayout>
    );
}
