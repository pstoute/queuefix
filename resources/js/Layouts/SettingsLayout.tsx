import { PropsWithChildren } from 'react';
import { Link, usePage } from '@inertiajs/react';
import AgentLayout from '@/Layouts/AgentLayout';
import { cn } from '@/lib/utils';
import {
  Settings,
  Paintbrush,
  Users,
  Building2,
  Mail,
  Clock,
  MessageSquare,
} from 'lucide-react';

const settingsNav = [
  { name: 'General', href: '/settings/general', icon: Settings, pattern: /^\/settings\/general/ },
  { name: 'Appearance', href: '/settings/appearance', icon: Paintbrush, pattern: /^\/settings\/appearance/ },
  { name: 'Users', href: '/settings/users', icon: Users, pattern: /^\/settings\/users/ },
  { name: 'Departments', href: '/settings/departments', icon: Building2, pattern: /^\/settings\/departments/ },
  { name: 'Mailboxes', href: '/settings/mailboxes', icon: Mail, pattern: /^\/settings\/mailboxes/ },
  { name: 'SLA Policies', href: '/settings/sla', icon: Clock, pattern: /^\/settings\/sla/ },
  { name: 'Canned Responses', href: '/settings/canned-responses', icon: MessageSquare, pattern: /^\/settings\/canned-responses/ },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
  const currentPath = usePage().url;

  return (
    <AgentLayout>
      <div className="container max-w-7xl mx-auto p-6">
        <div className="flex flex-col gap-6 md:flex-row">
          {/* Settings sidebar nav */}
          <nav className="md:w-56 shrink-0">
            <div className="flex flex-row gap-1 overflow-x-auto md:flex-col md:overflow-visible">
              {settingsNav.map((item) => {
                const Icon = item.icon;
                const active = item.pattern.test(currentPath);
                return (
                  <Link
                    key={item.name}
                    href={item.href}
                    className={cn(
                      'flex items-center gap-3 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                      active
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground'
                    )}
                  >
                    <Icon className="h-4 w-4 shrink-0" />
                    {item.name}
                  </Link>
                );
              })}
            </div>
          </nav>

          {/* Settings content */}
          <div className="flex-1 min-w-0">
            {children}
          </div>
        </div>
      </div>
    </AgentLayout>
  );
}
