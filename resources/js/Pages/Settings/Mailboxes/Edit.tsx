import { Head, useForm, Link } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import AgentLayout from '@/Layouts/AgentLayout';
import { PageProps, Mailbox, MailboxType } from '@/types';
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
import { Separator } from '@/Components/ui/separator';
import { Switch } from '@/Components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { ChevronLeft } from 'lucide-react';

interface EditMailboxProps extends PageProps {
    mailbox: Mailbox & {
        imap_host?: string;
        imap_port?: number;
        imap_encryption?: string;
        imap_username?: string;
        smtp_host?: string;
        smtp_port?: number;
        smtp_encryption?: string;
        smtp_username?: string;
    };
    types: Array<{ value: MailboxType; label: string }>;
}

export default function EditMailbox({ mailbox, types }: EditMailboxProps) {
    const { data, setData, put, processing, errors } = useForm({
        name: mailbox.name,
        email: mailbox.email,
        type: mailbox.type,
        department: mailbox.department || '',
        polling_interval: mailbox.polling_interval,
        is_active: mailbox.is_active,
        // IMAP settings
        imap_host: mailbox.imap_host || '',
        imap_port: mailbox.imap_port || 993,
        imap_encryption: mailbox.imap_encryption || 'ssl',
        imap_username: mailbox.imap_username || '',
        imap_password: '',
        // SMTP settings
        smtp_host: mailbox.smtp_host || '',
        smtp_port: mailbox.smtp_port || 587,
        smtp_encryption: mailbox.smtp_encryption || 'tls',
        smtp_username: mailbox.smtp_username || '',
        smtp_password: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('settings.mailboxes.update', mailbox.id));
    };

    const handleOAuthConnect = (provider: 'gmail' | 'microsoft') => {
        alert(`OAuth reconnection for ${provider} would be implemented here`);
    };

    return (
        <AgentLayout>
            <Head title={`Edit ${mailbox.name}`} />

            <div className="space-y-6">
                <div className="flex items-center gap-4">
                    <Link href={route('settings.mailboxes.index')}>
                        <Button variant="ghost" size="icon">
                            <ChevronLeft className="h-5 w-5" />
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Edit Mailbox</h1>
                        <p className="text-muted-foreground">Update mailbox configuration</p>
                    </div>
                </div>

                <Separator />

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
                                    <Label htmlFor="department">Department (Optional)</Label>
                                    <Input
                                        id="department"
                                        value={data.department}
                                        onChange={(e) => setData('department', e.target.value)}
                                        placeholder="Technical Support"
                                    />
                                    {errors.department && (
                                        <p className="text-sm text-destructive">{errors.department}</p>
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
                                                <Label htmlFor="imap_password">
                                                    Password{' '}
                                                    <span className="text-muted-foreground">
                                                        (leave blank to keep current)
                                                    </span>
                                                </Label>
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
                                                <Label htmlFor="smtp_password">
                                                    Password{' '}
                                                    <span className="text-muted-foreground">
                                                        (leave blank to keep current)
                                                    </span>
                                                </Label>
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
                                    Reconnect your Gmail account if needed
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => handleOAuthConnect('gmail')}
                                >
                                    Reconnect Gmail Account
                                </Button>
                            </CardContent>
                        </Card>
                    )}

                    {data.type === 'microsoft' && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Microsoft 365 OAuth</CardTitle>
                                <CardDescription>
                                    Reconnect your Microsoft 365 account if needed
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => handleOAuthConnect('microsoft')}
                                >
                                    Reconnect Microsoft Account
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
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </div>
        </AgentLayout>
    );
}
