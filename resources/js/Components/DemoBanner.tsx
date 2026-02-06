import { ExternalLink } from 'lucide-react';

interface DemoBannerProps {
    githubUrl: string;
    resetInterval: number;
}

export default function DemoBanner({ githubUrl, resetInterval }: DemoBannerProps) {
    return (
        <div className="bg-amber-500 text-amber-950 text-center text-sm py-1.5 px-4 font-medium shrink-0">
            This is a live demo &mdash; data resets every {resetInterval} minutes.{' '}
            <a
                href={githubUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="underline font-semibold inline-flex items-center gap-1"
            >
                View on GitHub
                <ExternalLink className="h-3 w-3" />
            </a>
        </div>
    );
}
