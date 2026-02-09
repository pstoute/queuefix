import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { PageProps } from '@/types';
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

interface GeneralSettingsProps extends PageProps {
    settings: Record<string, string>;
}

const timezones = [
    { value: 'UTC', label: 'UTC' },
    { value: 'America/New_York', label: 'Eastern Time (US & Canada)' },
    { value: 'America/Chicago', label: 'Central Time (US & Canada)' },
    { value: 'America/Denver', label: 'Mountain Time (US & Canada)' },
    { value: 'America/Los_Angeles', label: 'Pacific Time (US & Canada)' },
    { value: 'Europe/London', label: 'London' },
    { value: 'Europe/Paris', label: 'Paris' },
    { value: 'Asia/Tokyo', label: 'Tokyo' },
    { value: 'Australia/Sydney', label: 'Sydney' },
];

const languages = [
    { value: 'en', label: 'English' },
    { value: 'es', label: 'Spanish' },
    { value: 'fr', label: 'French' },
    { value: 'de', label: 'German' },
    { value: 'pt', label: 'Portuguese' },
];

export default function General({ settings }: GeneralSettingsProps) {
    const { data, setData, put, processing, errors } = useForm({
        app_name: settings.app_name || 'QueueFix',
        app_url: settings.app_url || '',
        timezone: settings.timezone || 'UTC',
        default_language: settings.default_language || 'en',
        ticket_prefix: settings.ticket_prefix || 'QF',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('settings.general.update'));
    };

    return (
        <SettingsLayout>
            <Head title="General Settings" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">General Settings</h1>
                    <p className="text-muted-foreground">
                        Configure basic application settings
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Application Information</CardTitle>
                        <CardDescription>
                            Basic information about your help desk application
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="space-y-2">
                                <Label htmlFor="app_name">Application Name</Label>
                                <Input
                                    id="app_name"
                                    value={data.app_name}
                                    onChange={(e) => setData('app_name', e.target.value)}
                                    placeholder="QueueFix"
                                />
                                {errors.app_name && (
                                    <p className="text-sm text-destructive">{errors.app_name}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="ticket_prefix">Ticket Prefix</Label>
                                <Input
                                    id="ticket_prefix"
                                    value={data.ticket_prefix}
                                    onChange={(e) => setData('ticket_prefix', e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, ''))}
                                    placeholder="QF"
                                    maxLength={10}
                                />
                                <p className="text-sm text-muted-foreground">
                                    Prefix for ticket numbers (e.g., {data.ticket_prefix || 'QF'}-1, {data.ticket_prefix || 'QF'}-2). Letters and numbers only.
                                </p>
                                {errors.ticket_prefix && (
                                    <p className="text-sm text-destructive">{errors.ticket_prefix}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="app_url">Application URL</Label>
                                <Input
                                    id="app_url"
                                    type="url"
                                    value={data.app_url}
                                    onChange={(e) => setData('app_url', e.target.value)}
                                    placeholder="https://tickets.example.com"
                                />
                                {errors.app_url && (
                                    <p className="text-sm text-destructive">{errors.app_url}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="timezone">Timezone</Label>
                                <Select
                                    value={data.timezone}
                                    onValueChange={(value) => setData('timezone', value)}
                                >
                                    <SelectTrigger id="timezone">
                                        <SelectValue placeholder="Select timezone" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {timezones.map((tz) => (
                                            <SelectItem key={tz.value} value={tz.value}>
                                                {tz.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.timezone && (
                                    <p className="text-sm text-destructive">{errors.timezone}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="default_language">Default Language</Label>
                                <Select
                                    value={data.default_language}
                                    onValueChange={(value) => setData('default_language', value)}
                                >
                                    <SelectTrigger id="default_language">
                                        <SelectValue placeholder="Select language" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {languages.map((lang) => (
                                            <SelectItem key={lang.value} value={lang.value}>
                                                {lang.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.default_language && (
                                    <p className="text-sm text-destructive">{errors.default_language}</p>
                                )}
                            </div>

                            <div className="flex justify-end">
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Saving...' : 'Save Changes'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </SettingsLayout>
    );
}
