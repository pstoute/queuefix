import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { PageProps } from '@/types';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Separator } from '@/Components/ui/separator';
import { Mail, Shield, UserCog } from 'lucide-react';

export default function Login({
    status,
    canResetPassword,
}: {
    status?: string;
    canResetPassword: boolean;
}) {
    const { demo } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    const quickLogin = (creds: { email: string; password: string }) => {
        router.post(route('login'), {
            email: creds.email,
            password: creds.password,
            remember: false,
        });
    };

    const handleSocialLogin = (provider: 'google' | 'microsoft') => {
        window.location.href = route('auth.social', provider);
    };

    return (
        <GuestLayout>
            <Head title="Log in" />

            <div className="space-y-6">
                <div className="space-y-2 text-center">
                    <h1 className="text-2xl font-semibold tracking-tight">Welcome back</h1>
                    <p className="text-sm text-muted-foreground">
                        Sign in to your account to continue
                    </p>
                </div>

                {status && (
                    <div className="rounded-lg bg-green-50 p-3 text-sm text-green-800">
                        {status}
                    </div>
                )}

                {demo?.enabled && (
                    <div className="rounded-lg border border-blue-200 bg-blue-50 p-4 space-y-3">
                        <p className="text-sm font-medium text-blue-900">
                            Demo Mode &mdash; Try QueueFix instantly
                        </p>
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                className="bg-white"
                                onClick={() => quickLogin(demo.credentials.admin)}
                            >
                                <Shield className="mr-1.5 h-3.5 w-3.5" />
                                Login as Admin
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                className="bg-white"
                                onClick={() => quickLogin(demo.credentials.agent)}
                            >
                                <UserCog className="mr-1.5 h-3.5 w-3.5" />
                                Login as Agent
                            </Button>
                        </div>
                        <div className="text-xs text-blue-700 space-y-0.5">
                            <p>Admin: {demo.credentials.admin.email} / {demo.credentials.admin.password}</p>
                            <p>Agent: {demo.credentials.agent.email} / {demo.credentials.agent.password}</p>
                        </div>
                    </div>
                )}

                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="email">Email</Label>
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            placeholder="name@example.com"
                            autoComplete="username"
                            autoFocus={!demo?.enabled}
                        />
                        {errors.email && (
                            <p className="text-sm text-destructive">{errors.email}</p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <div className="flex items-center justify-between">
                            <Label htmlFor="password">Password</Label>
                            {canResetPassword && (
                                <Link
                                    href={route('password.request')}
                                    className="text-sm text-primary hover:underline"
                                >
                                    Forgot password?
                                </Link>
                            )}
                        </div>
                        <Input
                            id="password"
                            type="password"
                            name="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            autoComplete="current-password"
                        />
                        {errors.password && (
                            <p className="text-sm text-destructive">{errors.password}</p>
                        )}
                    </div>

                    <div className="flex items-center space-x-2">
                        <input
                            id="remember"
                            type="checkbox"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                            className="h-4 w-4 rounded border-gray-300"
                        />
                        <Label htmlFor="remember" className="text-sm font-normal">
                            Remember me
                        </Label>
                    </div>

                    <Button type="submit" className="w-full" disabled={processing}>
                        {processing ? 'Signing in...' : 'Sign in'}
                    </Button>
                </form>

                <div className="relative">
                    <div className="absolute inset-0 flex items-center">
                        <Separator />
                    </div>
                    <div className="relative flex justify-center text-xs uppercase">
                        <span className="bg-white px-2 text-muted-foreground">
                            Or continue with
                        </span>
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => handleSocialLogin('google')}
                    >
                        <svg className="mr-2 h-4 w-4" viewBox="0 0 24 24">
                            <path
                                fill="currentColor"
                                d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                            />
                            <path
                                fill="currentColor"
                                d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                            />
                            <path
                                fill="currentColor"
                                d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                            />
                            <path
                                fill="currentColor"
                                d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                            />
                        </svg>
                        Google
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => handleSocialLogin('microsoft')}
                    >
                        <svg className="mr-2 h-4 w-4" viewBox="0 0 24 24">
                            <path fill="#f25022" d="M1 1h10v10H1z" />
                            <path fill="#00a4ef" d="M13 1h10v10H13z" />
                            <path fill="#7fba00" d="M1 13h10v10H1z" />
                            <path fill="#ffb900" d="M13 13h10v10H13z" />
                        </svg>
                        Microsoft
                    </Button>
                </div>

                <div className="text-center">
                    <Link
                        href={route('auth.magic-link')}
                        className="text-sm text-primary hover:underline"
                    >
                        <Mail className="mr-2 inline h-4 w-4" />
                        Sign in with Magic Link
                    </Link>
                </div>
            </div>
        </GuestLayout>
    );
}
