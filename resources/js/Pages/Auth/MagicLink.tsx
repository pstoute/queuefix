import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { ArrowLeft, Mail, CheckCircle } from 'lucide-react';

interface MagicLinkProps {
    status?: string;
}

export default function MagicLink({ status }: MagicLinkProps) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('auth.magic-link.send'));
    };

    return (
        <GuestLayout>
            <Head title="Magic Link Sign In" />

            <div className="space-y-6">
                <div className="space-y-2 text-center">
                    <h1 className="text-2xl font-semibold tracking-tight">Magic Link Sign In</h1>
                    <p className="text-sm text-muted-foreground">
                        Enter your email and we'll send you a secure sign-in link
                    </p>
                </div>

                {status ? (
                    <div className="space-y-4">
                        <div className="rounded-lg bg-green-50 p-4">
                            <div className="flex">
                                <CheckCircle className="h-5 w-5 text-green-400" />
                                <div className="ml-3">
                                    <p className="text-sm font-medium text-green-800">{status}</p>
                                    <p className="mt-2 text-sm text-green-700">
                                        Check your inbox and click the link to sign in. The link will
                                        expire in 15 minutes.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div className="text-center">
                            <Link href={route('login')}>
                                <Button variant="outline">
                                    <ArrowLeft className="mr-2 h-4 w-4" />
                                    Back to sign in
                                </Button>
                            </Link>
                        </div>
                    </div>
                ) : (
                    <>
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
                                        placeholder="name@example.com"
                                        className="pl-10"
                                        autoFocus
                                    />
                                </div>
                                {errors.email && (
                                    <p className="text-sm text-destructive">{errors.email}</p>
                                )}
                            </div>

                            <Button type="submit" className="w-full" disabled={processing}>
                                {processing ? 'Sending...' : 'Send Magic Link'}
                            </Button>
                        </form>

                        <div className="text-center">
                            <Link
                                href={route('login')}
                                className="inline-flex items-center text-sm text-primary hover:underline"
                            >
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to sign in
                            </Link>
                        </div>

                        <div className="rounded-lg border border-blue-200 bg-blue-50 p-4">
                            <div className="flex">
                                <Mail className="h-5 w-5 text-blue-400" />
                                <div className="ml-3">
                                    <h3 className="text-sm font-medium text-blue-800">
                                        What is a Magic Link?
                                    </h3>
                                    <p className="mt-2 text-sm text-blue-700">
                                        A magic link is a secure, one-time use link sent to your email
                                        that allows you to sign in without a password. It's more secure
                                        and convenient than traditional passwords.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </>
                )}
            </div>
        </GuestLayout>
    );
}
