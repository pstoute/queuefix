import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Mail, CheckCircle } from 'lucide-react';

interface CustomerLoginProps {
    status?: string;
    appName?: string;
}

export default function CustomerLogin({ status, appName = 'QueueFix' }: CustomerLoginProps) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('customer.login.send'));
    };

    return (
        <>
            <Head title="Customer Sign In" />

            <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4 py-12 sm:px-6 lg:px-8">
                <div className="w-full max-w-md">
                    <div className="text-center">
                        <h1 className="text-3xl font-bold text-gray-900">{appName}</h1>
                        <p className="mt-2 text-sm text-gray-600">Customer Support Portal</p>
                    </div>

                    <Card className="mt-8">
                        <CardHeader className="space-y-1">
                            <CardTitle className="text-2xl">Sign in</CardTitle>
                            <CardDescription>
                                Enter your email to receive a sign-in link
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {status ? (
                                <div className="rounded-lg bg-green-50 p-4">
                                    <div className="flex">
                                        <CheckCircle className="h-5 w-5 text-green-400" />
                                        <div className="ml-3">
                                            <p className="text-sm font-medium text-green-800">
                                                {status}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <form onSubmit={submit} className="space-y-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="email">Email Address</Label>
                                        <div className="relative">
                                            <Mail className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                                            <Input
                                                id="email"
                                                type="email"
                                                value={data.email}
                                                onChange={(e) => setData('email', e.target.value)}
                                                placeholder="you@example.com"
                                                className="pl-10"
                                                autoFocus
                                            />
                                        </div>
                                        {errors.email && (
                                            <p className="text-sm text-destructive">{errors.email}</p>
                                        )}
                                    </div>

                                    <Button
                                        type="submit"
                                        className="w-full"
                                        disabled={processing}
                                    >
                                        {processing ? 'Sending...' : 'Send Sign-in Link'}
                                    </Button>

                                    <p className="text-center text-xs text-gray-500">
                                        We'll email you a magic link for a password-free sign in
                                    </p>
                                </form>
                            )}
                        </CardContent>
                    </Card>

                    <p className="mt-4 text-center text-sm text-gray-600">
                        Need help?{' '}
                        <a href="mailto:support@example.com" className="font-medium text-primary hover:underline">
                            Contact support
                        </a>
                    </p>
                </div>
            </div>
        </>
    );
}
