import { Head, useForm, Link } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { PageProps, MailboxType, Department } from '@/types';
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
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Switch } from '@/Components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { ChevronLeft, Plus, Trash2 } from 'lucide-react';

interface CreateMailboxProps extends PageProps {
    types: Array<{ value: MailboxType; label: string }>;
    departments: Array<{ id: string; name: string }>;
}

export default function CreateMailbox({ types, departments }: CreateMailboxProps) {
    const [aliases, setAliases] = useState<Array<{ email: string; department_id: string }>>([]);

    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',
        email: '',
        type: 'imap' as MailboxType,
        department_id: '',
        polling_interval: 5,
        is_active: true,
        // IMAP settings
        imap_host: '',
        imap_port: 993,
        imap_encryption: 'ssl',
        imap_username: '',
        imap_password: '',
        // SMTP settings
        smtp_host: '',
        smtp_port: 587,
        smtp_encryption: 'tls',
        smtp_username: '',
        smtp_password: '',
    });

    const addAlias = () => {
        setAliases([...aliases, { email: '', department_id: '' }]);
    };

    const removeAlias = (index: number) => {
        setAliases(aliases.filter((_, i) => i !== index));
    };

    const updateAlias = (index: number, field: 'email' | 'department_id', value: string) => {
        const updated = [...aliases];
        updated[index] = { ...updated[index], [field]: value };
        setAliases(updated);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((formData) => ({
            ...formData,
            aliases: aliases.filter((a) => a.email && a.department_id),
        }));
        post(route('settings.mailboxes.store'));
    };

    const handleOAuthConnect = (provider: 'gmail' | 'microsoft') => {
        // This would trigger OAuth flow
        alert(`OAuth connection for ${provider} would be implemented here`);
    };

    return (
        <SettingsLayout>
            <Head title="Add Mailbox" />

            <div className="space-y-6">
                <div className="flex items-center gap-4">
                    <Link href={route('settings.mailboxes.index')}>
                        <Button variant="ghost" size="icon">
                            <ChevronLeft className="h-5 w-5" />
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Add Mailbox</h1>
                        <p className="text-muted-foreground">
                            Connect a new email account to receive tickets
                        </p>
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Basic Information</CardTitle>
                            <CardDescription>
                                Configure the basic mailbox settings
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="name">Mailbox Name</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Support"
                                    />
                                    {errors.name && (
                                        <p className="text-sm text-destructive">{errors.name}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="email">Email Address</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        placeholder="support@example.com"
                                    />
                                    {errors.email && (
                                        <p className="text-sm text-destructive">{errors.email}</p>
                                    )}
                                </div>
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="type">Mailbox Type</Label>
                                    <Select
                                        value={data.type}
                                        onValueChange={(value: MailboxType) => setData('type', value)}
                                    >
                                        <SelectTrigger id="type">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {types.map((type) => (
                                                <SelectItem key={type.value} value={type.value}>
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.type && (
                                        <p className="text-sm text-destructive">{errors.type}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="department_id">Default Department</Label>
                                    <Select
                                        value={data.department_id}
                                        onValueChange={(value) => setData('department_id', value === 'none' ? '' : value)}
                                    >
                                        <SelectTrigger id="department_id">
                                            <SelectValue placeholder="Select department..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">None</SelectItem>
                                            {departments.map((dept) => (
                                                <SelectItem key={dept.id} value={dept.id}>
                                                    {dept.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-muted-foreground">
                                        Catch-all department for unmatched aliases
                                    </p>
                                    {errors.department_id && (
                                        <p className="text-sm text-destructive">{errors.department_id}</p>
                                    )}
                                </div>
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="polling_interval">
                                        Polling Interval (minutes)
                                    </Label>
                                    <Input
                                        id="polling_interval"
                                        type="number"
                                        min="1"
                                        value={data.polling_interval}
                                        onChange={(e) =>
                                            setData('polling_interval', parseInt(e.target.value))
                                        }
                                    />
                                    {errors.polling_interval && (
                                        <p className="text-sm text-destructive">
                                            {errors.polling_interval}
                                        </p>
                                    )}
                                </div>

                                <div className="flex items-center gap-2 pt-8">
                                    <Switch
                                        id="is_active"
                                        checked={data.is_active}
                                        onCheckedChange={(checked) => setData('is_active', checked)}
                                    />
                                    <Label htmlFor="is_active">Active</Label>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Email Aliases</CardTitle>
                            <CardDescription>
                                Route alias email addresses to specific departments
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {aliases.map((alias, index) => (
                                <div key={index} className="flex items-start gap-3">
                                    <div className="flex-1 space-y-2">
                                        <Input
                                            type="email"
                                            value={alias.email}
                                            onChange={(e) => updateAlias(index, 'email', e.target.value)}
                                            placeholder="billing@example.com"
                                        />
                                    </div>
                                    <div className="flex-1">
                                        <Select
                                            value={alias.department_id}
                                            onValueChange={(value) => updateAlias(index, 'department_id', value)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select department..." />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {departments.map((dept) => (
                                                    <SelectItem key={dept.id} value={dept.id}>
                                                        {dept.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => removeAlias(index)}
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                </div>
                            ))}
                            <Button type="button" variant="outline" size="sm" onClick={addAlias}>
                                <Plus className="mr-2 h-4 w-4" />
                                Add Alias
                            </Button>
                            {aliases.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    No aliases configured. Emails not matching any alias will use the default department.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {data.type === 'imap' && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Connection Settings</CardTitle>
                                <CardDescription>
                                    Configure IMAP and SMTP server settings
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Tabs defaultValue="imap" className="w-full">
                                    <TabsList className="grid w-full grid-cols-2">
                                        <TabsTrigger value="imap">Incoming (IMAP)</TabsTrigger>
                                        <TabsTrigger value="smtp">Outgoing (SMTP)</TabsTrigger>
                                    </TabsList>
                                    <TabsContent value="imap" className="space-y-4">
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label htmlFor="imap_host">IMAP Host</Label>
                                                <Input
                                                    id="imap_host"
                                                    value={data.imap_host}
                                                    onChange={(e) =>
                                                        setData('imap_host', e.target.value)
                                                    }
                                                    placeholder="imap.example.com"
                                                />
                                                {errors.imap_host && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.imap_host}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="imap_port">Port</Label>
                                                <Input
                                                    id="imap_port"
                                                    type="number"
                                                    value={data.imap_port}
                                                    onChange={(e) =>
                                                        setData('imap_port', parseInt(e.target.value))
                                                    }
                                                    placeholder="993"
                                                />
                                                {errors.imap_port && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.imap_port}
                                                    </p>
                                                )}
                                            </div>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="imap_encryption">Encryption</Label>
                                            <Select
                                                value={data.imap_encryption}
                                                onValueChange={(value) =>
                                                    setData('imap_encryption', value)
                                                }
                                            >
                                                <SelectTrigger id="imap_encryption">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="ssl">SSL</SelectItem>
                                                    <SelectItem value="tls">TLS</SelectItem>
                                                    <SelectItem value="none">None</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label htmlFor="imap_username">Username</Label>
                                                <Input
                                                    id="imap_username"
                                                    value={data.imap_username}
                                                    onChange={(e) =>
                                                        setData('imap_username', e.target.value)
                                                    }
                                                    placeholder="user@example.com"
                                                />
                                                {errors.imap_username && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.imap_username}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="imap_password">Password</Label>
                                                <Input
                                                    id="imap_password"
                                                    type="password"
                                                    value={data.imap_password}
                                                    onChange={(e) =>
                                                        setData('imap_password', e.target.value)
                                                    }
                                                />
                                                {errors.imap_password && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.imap_password}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </TabsContent>
                                    <TabsContent value="smtp" className="space-y-4">
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label htmlFor="smtp_host">SMTP Host</Label>
                                                <Input
                                                    id="smtp_host"
                                                    value={data.smtp_host}
                                                    onChange={(e) =>
                                                        setData('smtp_host', e.target.value)
                                                    }
                                                    placeholder="smtp.example.com"
                                                />
                                                {errors.smtp_host && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.smtp_host}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="smtp_port">Port</Label>
                                                <Input
                                                    id="smtp_port"
                                                    type="number"
                                                    value={data.smtp_port}
                                                    onChange={(e) =>
                                                        setData('smtp_port', parseInt(e.target.value))
                                                    }
                                                    placeholder="587"
                                                />
                                                {errors.smtp_port && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.smtp_port}
                                                    </p>
                                                )}
                                            </div>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="smtp_encryption">Encryption</Label>
                                            <Select
                                                value={data.smtp_encryption}
                                                onValueChange={(value) =>
                                                    setData('smtp_encryption', value)
                                                }
                                            >
                                                <SelectTrigger id="smtp_encryption">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="tls">TLS</SelectItem>
                                                    <SelectItem value="ssl">SSL</SelectItem>
                                                    <SelectItem value="none">None</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label htmlFor="smtp_username">Username</Label>
                                                <Input
                                                    id="smtp_username"
                                                    value={data.smtp_username}
                                                    onChange={(e) =>
                                                        setData('smtp_username', e.target.value)
                                                    }
                                                    placeholder="user@example.com"
                                                />
                                                {errors.smtp_username && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.smtp_username}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="smtp_password">Password</Label>
                                                <Input
                                                    id="smtp_password"
                                                    type="password"
                                                    value={data.smtp_password}
                                                    onChange={(e) =>
                                                        setData('smtp_password', e.target.value)
                                                    }
                                                />
                                                {errors.smtp_password && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.smtp_password}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </TabsContent>
                                </Tabs>
                            </CardContent>
                        </Card>
                    )}

                    {data.type === 'gmail' && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Gmail OAuth</CardTitle>
                                <CardDescription>
                                    Connect your Gmail account using OAuth 2.0
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => handleOAuthConnect('gmail')}
                                >
                                    Connect Gmail Account
                                </Button>
                            </CardContent>
                        </Card>
                    )}

                    {data.type === 'microsoft' && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Microsoft 365 OAuth</CardTitle>
                                <CardDescription>
                                    Connect your Microsoft 365 account using OAuth 2.0
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => handleOAuthConnect('microsoft')}
                                >
                                    Connect Microsoft Account
                                </Button>
                            </CardContent>
                        </Card>
                    )}

                    <div className="flex justify-end gap-3">
                        <Link href={route('settings.mailboxes.index')}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create Mailbox'}
                        </Button>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    );
}
