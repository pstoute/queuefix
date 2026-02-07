import AgentLayout from '@/Layouts/AgentLayout';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Separator } from '@/Components/ui/separator';
import { Button } from '@/Components/ui/button';
import { useTheme } from '@/lib/hooks';
import { Sun, Moon, Monitor } from 'lucide-react';
import { cn } from '@/lib/utils';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({
    mustVerifyEmail,
    status,
}: PageProps<{ mustVerifyEmail: boolean; status?: string }>) {
    const { theme, setTheme } = useTheme();

    const themeOptions = [
        { value: 'light' as const, label: 'Light', icon: Sun },
        { value: 'dark' as const, label: 'Dark', icon: Moon },
        { value: 'system' as const, label: 'System', icon: Monitor },
    ];

    return (
        <AgentLayout>
            <Head title="Profile" />

            <div className="container max-w-7xl mx-auto p-6 space-y-6">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">Profile</h1>
                    <p className="text-muted-foreground">
                        Manage your account settings and preferences
                    </p>
                </div>

                <Separator />

                <Card>
                    <CardHeader>
                        <CardTitle>Profile Information</CardTitle>
                        <CardDescription>
                            Update your account's profile information and email address.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                            className="max-w-xl"
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Update Password</CardTitle>
                        <CardDescription>
                            Ensure your account is using a long, random password to stay secure.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <UpdatePasswordForm className="max-w-xl" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Appearance</CardTitle>
                        <CardDescription>
                            Choose your preferred theme for the application.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex gap-3">
                            {themeOptions.map((option) => {
                                const Icon = option.icon;
                                const isSelected = theme === option.value;
                                return (
                                    <Button
                                        key={option.value}
                                        variant={isSelected ? 'default' : 'outline'}
                                        size="sm"
                                        onClick={() => setTheme(option.value)}
                                        className={cn('gap-2', isSelected && 'pointer-events-none')}
                                    >
                                        <Icon className="h-4 w-4" />
                                        {option.label}
                                    </Button>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Delete Account</CardTitle>
                        <CardDescription>
                            Permanently delete your account and all associated data.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <DeleteUserForm className="max-w-xl" />
                    </CardContent>
                </Card>
            </div>
        </AgentLayout>
    );
}
