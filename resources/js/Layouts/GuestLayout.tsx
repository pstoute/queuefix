import { Link, usePage } from '@inertiajs/react';
import { PropsWithChildren } from 'react';
import { Card, CardContent } from '@/Components/ui/card';

export default function Guest({ children }: PropsWithChildren) {
    const { props } = usePage<{ appName?: string }>();
    const appName = props.appName || 'QueueFix';

    return (
        <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-gray-50 to-gray-100 px-4 py-12 sm:px-6 lg:px-8">
            <div className="w-full max-w-md space-y-8">
                <div className="text-center">
                    <Link href="/" className="inline-block">
                        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary text-2xl font-bold text-primary-foreground shadow-lg">
                            QF
                        </div>
                    </Link>
                    <h2 className="mt-6 text-3xl font-bold text-gray-900">{appName}</h2>
                    <p className="mt-2 text-sm text-gray-600">Support Ticket Management</p>
                </div>

                <Card className="shadow-xl">
                    <CardContent className="pt-6">
                        {children}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
