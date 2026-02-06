import { useState, useEffect, PropsWithChildren } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import { useTheme } from '@/lib/hooks';
import { cn } from '@/lib/utils';
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
import { Separator } from '@/Components/ui/separator';
import { Toaster } from '@/Components/ui/toaster';
import { useToast } from '@/Components/ui/use-toast';
import {
  LayoutDashboard,
  Inbox,
  Settings,
  Menu,
  X,
  Sun,
  Moon,
  Monitor,
  LogOut,
  User,
} from 'lucide-react';

interface NavItem {
  name: string;
  href: string;
  icon: typeof LayoutDashboard;
  pattern?: RegExp;
}

const navigation: NavItem[] = [
  { name: 'Dashboard', href: '/agent/dashboard', icon: LayoutDashboard, pattern: /^\/agent\/dashboard/ },
  { name: 'Tickets', href: '/agent/tickets', icon: Inbox, pattern: /^\/agent\/tickets/ },
  { name: 'Settings', href: '/agent/settings', icon: Settings, pattern: /^\/agent\/settings/ },
];

export default function AgentLayout({ children }: PropsWithChildren) {
  const { auth, flash } = usePage<PageProps>().props;
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const { theme, setTheme } = useTheme();
  const { toast } = useToast();
  const currentPath = usePage().url;

  useEffect(() => {
    if (flash?.success) {
      toast({
        title: 'Success',
        description: flash.success,
      });
    }
    if (flash?.error) {
      toast({
        title: 'Error',
        description: flash.error,
        variant: 'destructive',
      });
    }
  }, [flash]);

  const isActive = (item: NavItem) => {
    if (item.pattern) {
      return item.pattern.test(currentPath);
    }
    return currentPath === item.href;
  };

  const handleLogout = () => {
    router.post('/logout');
  };

  const getInitials = (name: string) => {
    return name
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  };

  const cycleTheme = () => {
    const themes: Array<'light' | 'dark' | 'system'> = ['light', 'dark', 'system'];
    const currentIndex = themes.indexOf(theme);
    const nextIndex = (currentIndex + 1) % themes.length;
    setTheme(themes[nextIndex]);
  };

  const ThemeIcon = theme === 'light' ? Sun : theme === 'dark' ? Moon : Monitor;

  return (
    <div className="flex h-screen overflow-hidden bg-background">
      {/* Mobile sidebar backdrop */}
      {sidebarOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/50 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      {/* Sidebar */}
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-50 w-64 transform bg-card border-r transition-transform duration-200 ease-in-out lg:relative lg:translate-x-0',
          sidebarOpen ? 'translate-x-0' : '-translate-x-full'
        )}
      >
        <div className="flex h-full flex-col">
          {/* Logo and close button */}
          <div className="flex h-16 items-center justify-between px-6">
            <Link href="/agent/dashboard" className="flex items-center space-x-2">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-primary-foreground font-semibold">
                ST
              </div>
              <span className="text-lg font-semibold">SimpleTickets</span>
            </Link>
            <Button
              variant="ghost"
              size="icon"
              className="lg:hidden"
              onClick={() => setSidebarOpen(false)}
            >
              <X className="h-5 w-5" />
            </Button>
          </div>

          <Separator />

          {/* Navigation */}
          <nav className="flex-1 space-y-1 px-3 py-4">
            {navigation.map((item) => {
              const Icon = item.icon;
              const active = isActive(item);
              return (
                <Link
                  key={item.name}
                  href={item.href}
                  className={cn(
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                    active
                      ? 'bg-primary text-primary-foreground'
                      : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                  )}
                  onClick={() => setSidebarOpen(false)}
                >
                  <Icon className="h-5 w-5 shrink-0" />
                  {item.name}
                </Link>
              );
            })}
          </nav>

          <Separator />

          {/* Theme toggle */}
          <div className="px-3 py-3">
            <Button
              variant="ghost"
              size="sm"
              className="w-full justify-start gap-3"
              onClick={cycleTheme}
            >
              <ThemeIcon className="h-5 w-5" />
              <span className="text-sm">
                {theme === 'light' ? 'Light' : theme === 'dark' ? 'Dark' : 'System'}
              </span>
            </Button>
          </div>

          <Separator />

          {/* User menu */}
          <div className="p-3">
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="ghost"
                  className="w-full justify-start gap-3 px-2 hover:bg-muted"
                >
                  <Avatar className="h-8 w-8">
                    <AvatarImage src={auth.user.avatar} alt={auth.user.name} />
                    <AvatarFallback>{getInitials(auth.user.name)}</AvatarFallback>
                  </Avatar>
                  <div className="flex flex-1 flex-col items-start text-left">
                    <span className="text-sm font-medium">{auth.user.name}</span>
                    <span className="text-xs text-muted-foreground">{auth.user.email}</span>
                  </div>
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel>My Account</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                  <Link href="/agent/profile" className="cursor-pointer">
                    <User className="mr-2 h-4 w-4" />
                    Profile
                  </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem onClick={handleLogout} className="cursor-pointer text-destructive">
                  <LogOut className="mr-2 h-4 w-4" />
                  Logout
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>
      </aside>

      {/* Main content */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {/* Mobile header */}
        <header className="flex h-16 items-center gap-4 border-b bg-card px-4 lg:hidden">
          <Button variant="ghost" size="icon" onClick={() => setSidebarOpen(true)}>
            <Menu className="h-5 w-5" />
          </Button>
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-primary-foreground font-semibold">
            ST
          </div>
          <span className="text-lg font-semibold">SimpleTickets</span>
        </header>

        {/* Page content */}
        <main className="flex-1 overflow-y-auto">
          {children}
        </main>
      </div>

      <Toaster />
    </div>
  );
}
