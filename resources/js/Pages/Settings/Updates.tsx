import { Head } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { PageProps } from '@/types';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { ExternalLink, RefreshCw } from 'lucide-react';

interface UpdateCheck {
  installedVersion: string;
  latestVersion: string | null;
  releaseUrl: string | null;
  publishedAt: string | null;
  notes: string | null;
  updateAvailable: boolean;
  error: string | null;
}

interface UpdatesProps extends PageProps {
  updateCheck: UpdateCheck;
}

export default function Updates({ updateCheck }: UpdatesProps) {
  return (
    <SettingsLayout>
      <Head title="Updates" />

      <div className="space-y-6">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Updates</h1>
          <p className="text-muted-foreground">
            Check the installed version against QueueFix releases. Updates are never installed automatically.
          </p>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Release status</CardTitle>
            <CardDescription>
              QueueFix checks GitHub release metadata at most twice a day. No tickets, email, or account data leaves this installation.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-5">
            <div className="flex flex-wrap items-center gap-3">
              <span className="text-sm text-muted-foreground">Installed</span>
              <Badge variant="secondary">{updateCheck.installedVersion}</Badge>
              {updateCheck.latestVersion && (
                <>
                  <span className="text-sm text-muted-foreground">Latest</span>
                  <Badge variant={updateCheck.updateAvailable ? 'default' : 'secondary'}>
                    {updateCheck.latestVersion}
                  </Badge>
                </>
              )}
            </div>

            {updateCheck.error ? (
              <p className="text-sm text-muted-foreground">{updateCheck.error}</p>
            ) : updateCheck.updateAvailable ? (
              <p className="text-sm">
                A newer release is available. Review its notes and the upgrade guide before making any change.
              </p>
            ) : (
              <p className="text-sm text-muted-foreground">This installation is up to date.</p>
            )}

            <div className="flex flex-wrap gap-3">
              {updateCheck.releaseUrl && (
                <Button asChild>
                  <a href={updateCheck.releaseUrl} target="_blank" rel="noreferrer">
                    View release notes <ExternalLink className="ml-2 h-4 w-4" />
                  </a>
                </Button>
              )}
              <Button variant="outline" asChild>
                <a href="https://github.com/pstoute/queuefix/blob/main/docs/upgrading-docker.md" target="_blank" rel="noreferrer">
                  Upgrade guide <RefreshCw className="ml-2 h-4 w-4" />
                </a>
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </SettingsLayout>
  );
}
