import { Link, router, usePage } from '@inertiajs/react';
import { PropsWithChildren } from 'react';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { LogOut, User } from 'lucide-react';
import { Customer } from '@/types';

interface CustomerLayoutProps extends PropsWithChildren {
    customer?: Customer;
}

export default function CustomerLayout({ customer, children }: CustomerLayoutProps) {
    const { props } = usePage<{ appName: string }>();
    const appName = props.appName || 'QueueFix';

    const handleLogout = () => {
        router.post(route('customer.logout'));
    };

    const getInitials = (name: string) => {
        return name
            .split(' ')
            .map((n) => n[0])
            .join('')
            .toUpperCase()
            .slice(0, 2);
    };

    return (
        <div className="flex min-h-screen flex-col bg-gray-50">
            {/* Header */}
            <header className="border-b border-gray-200 bg-white">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 items-center justify-between">
                        <Link
                            href={route('customer.tickets.index')}
                            className="text-xl font-semibold text-gray-900"
                        >
                            {appName}
                        </Link>

                        {customer && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" className="gap-2">
                                        <Avatar className="h-8 w-8">
                                            <AvatarImage src={customer.avatar} />
                                            <AvatarFallback>
                                                {getInitials(customer.name)}
                                            </AvatarFallback>
                                        </Avatar>
                                        <span className="hidden sm:inline">{customer.name}</span>
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-56">
                                    <DropdownMenuLabel>
                                        <div className="flex flex-col space-y-1">
                                            <p className="text-sm font-medium">{customer.name}</p>
                                            <p className="text-xs text-muted-foreground">
                                                {customer.email}
                                            </p>
                                        </div>
                                    </DropdownMenuLabel>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem onClick={handleLogout}>
                                        <LogOut className="mr-2 h-4 w-4" />
                                        Log out
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                </div>
            </header>

            {/* Main content */}
            <main className="flex-1">
                <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">{children}</div>
            </main>

            {/* Footer */}
            <footer className="border-t border-gray-200 bg-white">
                <div className="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
                    <p className="text-center text-sm text-gray-500">
                        &copy; {new Date().getFullYear()} {appName}. All rights reserved.
                    </p>
                </div>
            </footer>
        </div>
    );
}
