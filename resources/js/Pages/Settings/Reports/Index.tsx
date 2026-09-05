import { FormEvent, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { PageProps } from '@/types';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';

type MetricValue = number | null;

interface ReportSummary {
    created_count: number;
    resolved_count: number;
    currently_open_count: number;
    first_response_sla_percent: MetricValue;
    resolution_sla_percent: MetricValue;
    first_response_median_seconds: MetricValue;
    first_response_average_seconds: MetricValue;
    resolution_median_seconds: MetricValue;
    resolution_average_seconds: MetricValue;
    rating_count: number;
    average_csat: MetricValue;
    low_rating_percent: MetricValue;
}

interface BreakdownRow {
    key: string;
    label: string;
    created_count: number;
    resolved_count: number;
    currently_open_count: number;
    first_response_sla_percent: MetricValue;
    resolution_sla_percent: MetricValue;
    rating_count: number;
    average_csat: MetricValue;
    low_rating_percent: MetricValue;
}

interface ReportFilters {
    from: string;
    to: string;
    timezone: string;
    department_id: string | null;
    agent_id: string | null;
}

interface ReportsIndexProps extends PageProps {
    report: {
        summary: ReportSummary;
        breakdowns: Record<'department' | 'priority' | 'status' | 'assignee', BreakdownRow[]>;
    };
    filters: ReportFilters;
    departments: Array<{ id: string; name: string }>;
    agents: Array<{ id: string; name: string; is_active: boolean }>;
    timezones: string[];
}

const metricCards: Array<{
    key: keyof ReportSummary;
    label: string;
    format: 'count' | 'percent' | 'duration' | 'rating';
    definition: string;
}> = [
    {
        key: 'created_count',
        label: 'Created',
        format: 'count',
        definition: 'Tickets whose creation timestamp falls inside the selected local calendar dates.',
    },
    {
        key: 'resolved_count',
        label: 'Resolved',
        format: 'count',
        definition: 'Tickets whose first resolution timestamp falls inside the selected local calendar dates.',
    },
    {
        key: 'currently_open_count',
        label: 'Currently open',
        format: 'count',
        definition: 'Tickets created before the range end that are in a non-closed status when this report runs.',
    },
    {
        key: 'first_response_sla_percent',
        label: 'First-response SLA',
        format: 'percent',
        definition: 'Share of first responses completed in the period that did not breach their SLA timer.',
    },
    {
        key: 'resolution_sla_percent',
        label: 'Resolution SLA',
        format: 'percent',
        definition: 'Share of SLA resolutions completed in the period that did not breach their SLA timer.',
    },
    {
        key: 'first_response_median_seconds',
        label: 'Median first response',
        format: 'duration',
        definition: 'Median elapsed time for first responses completed in the period, excluding overlapping SLA pauses.',
    },
    {
        key: 'first_response_average_seconds',
        label: 'Average first response',
        format: 'duration',
        definition: 'Mean elapsed time for first responses completed in the period, excluding overlapping SLA pauses.',
    },
    {
        key: 'resolution_median_seconds',
        label: 'Median resolution',
        format: 'duration',
        definition: 'Median elapsed time for SLA resolutions completed in the period, excluding overlapping SLA pauses.',
    },
    {
        key: 'resolution_average_seconds',
        label: 'Average resolution',
        format: 'duration',
        definition: 'Mean elapsed time for SLA resolutions completed in the period, excluding overlapping SLA pauses.',
    },
    {
        key: 'rating_count',
        label: 'Ratings',
        format: 'count',
        definition: 'Customer ratings submitted inside the selected local calendar dates.',
    },
    {
        key: 'average_csat',
        label: 'Average CSAT',
        format: 'rating',
        definition: 'Arithmetic mean of 1–5 ratings submitted in the period; no ratings display as an em dash.',
    },
    {
        key: 'low_rating_percent',
        label: 'Low-rating rate',
        format: 'percent',
        definition: 'Share of ratings submitted in the period with a score of 1 or 2.',
    },
];

const breakdownTitles = {
    department: 'By department',
    priority: 'By priority',
    status: 'By current status',
    assignee: 'By current assignee',
};

function formatDuration(value: MetricValue): string {
    if (value === null) return '—';

    const seconds = Math.max(0, Math.round(value));
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    if (days > 0) return `${days}d ${hours}h`;
    if (hours > 0) return `${hours}h ${minutes}m`;
    if (minutes > 0) return `${minutes}m`;
    return `${seconds}s`;
}

function formatMetric(value: MetricValue, format: 'count' | 'percent' | 'duration' | 'rating'): string {
    if (value === null) return '—';
    if (format === 'percent') return `${value}%`;
    if (format === 'duration') return formatDuration(value);
    if (format === 'rating') return `${value.toFixed(2)} / 5`;
    return value.toLocaleString();
}

export default function ReportsIndex({ report, filters, departments, agents, timezones }: ReportsIndexProps) {
    const [form, setForm] = useState({
        from: filters.from,
        to: filters.to,
        timezone: filters.timezone,
        department_id: filters.department_id || 'all',
        agent_id: filters.agent_id || 'all',
    });

    const requestFilters = useMemo(() => ({
        from: form.from,
        to: form.to,
        timezone: form.timezone,
        ...(form.department_id !== 'all' ? { department_id: form.department_id } : {}),
        ...(form.agent_id !== 'all' ? { agent_id: form.agent_id } : {}),
    }), [form]);

    const exportUrl = `${route('settings.reports.export')}?${new URLSearchParams(requestFilters).toString()}`;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('settings.reports.index'), requestFilters, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <SettingsLayout>
            <Head title="Support Reports" />

            <div className="space-y-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Support Reports</h1>
                        <p className="text-muted-foreground">
                            Aggregate performance and satisfaction metrics without customer message content or feedback exports.
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <a href={exportUrl}>
                            <Download className="mr-2 h-4 w-4" />
                            Export CSV
                        </a>
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Report range</CardTitle>
                        <CardDescription>
                            Dates are interpreted in {filters.timezone}; the end date includes its entire local calendar day.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                            <div className="space-y-2">
                                <Label htmlFor="report-from">From</Label>
                                <Input
                                    id="report-from"
                                    type="date"
                                    required
                                    value={form.from}
                                    onChange={(event) => setForm({ ...form, from: event.target.value })}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="report-to">To</Label>
                                <Input
                                    id="report-to"
                                    type="date"
                                    required
                                    min={form.from}
                                    value={form.to}
                                    onChange={(event) => setForm({ ...form, to: event.target.value })}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="report-timezone">Timezone</Label>
                                <select
                                    id="report-timezone"
                                    value={form.timezone}
                                    onChange={(event) => setForm({ ...form, timezone: event.target.value })}
                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                >
                                    {timezones.map((timezone) => (
                                        <option key={timezone} value={timezone}>{timezone}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="report-department">Department</Label>
                                <Select
                                    value={form.department_id}
                                    onValueChange={(value) => setForm({ ...form, department_id: value })}
                                >
                                    <SelectTrigger id="report-department"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All departments</SelectItem>
                                        {departments.map((department) => (
                                            <SelectItem key={department.id} value={department.id}>{department.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="report-assignee">Assignee</Label>
                                <Select
                                    value={form.agent_id}
                                    onValueChange={(value) => setForm({ ...form, agent_id: value })}
                                >
                                    <SelectTrigger id="report-assignee"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All assignees</SelectItem>
                                        {agents.map((agent) => (
                                            <SelectItem key={agent.id} value={agent.id}>
                                                {agent.name}{agent.is_active ? '' : ' (inactive)'}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="md:col-span-2 xl:col-span-5 flex justify-end">
                                <Button type="submit">Apply filters</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {metricCards.map((metric) => (
                        <Card key={metric.key}>
                            <CardHeader className="pb-2">
                                <CardDescription>{metric.label}</CardDescription>
                                <CardTitle className="text-2xl">
                                    {formatMetric(report.summary[metric.key], metric.format)}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-xs leading-relaxed text-muted-foreground">{metric.definition}</p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="space-y-2">
                    <h2 className="text-2xl font-semibold">Breakdowns</h2>
                    <p className="text-sm text-muted-foreground">
                        Columns reuse the metric definitions above. Groups use each ticket&apos;s current department, priority, status, and assignee. A missing denominator is shown as —.
                    </p>
                </div>

                {(Object.keys(breakdownTitles) as Array<keyof typeof breakdownTitles>).map((dimension) => (
                    <Card key={dimension}>
                        <CardHeader>
                            <CardTitle>{breakdownTitles[dimension]}</CardTitle>
                        </CardHeader>
                        <CardContent className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Group</TableHead>
                                        <TableHead className="text-right">Created</TableHead>
                                        <TableHead className="text-right">Resolved</TableHead>
                                        <TableHead className="text-right">Open now</TableHead>
                                        <TableHead className="text-right">Response SLA</TableHead>
                                        <TableHead className="text-right">Resolution SLA</TableHead>
                                        <TableHead className="text-right">Ratings</TableHead>
                                        <TableHead className="text-right">Avg CSAT</TableHead>
                                        <TableHead className="text-right">Low ratings</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {report.breakdowns[dimension].length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={9} className="text-center text-muted-foreground">
                                                No aggregate data for this range.
                                            </TableCell>
                                        </TableRow>
                                    ) : report.breakdowns[dimension].map((row) => (
                                        <TableRow key={row.key}>
                                            <TableCell className="font-medium">{row.label}</TableCell>
                                            <TableCell className="text-right">{row.created_count.toLocaleString()}</TableCell>
                                            <TableCell className="text-right">{row.resolved_count.toLocaleString()}</TableCell>
                                            <TableCell className="text-right">{row.currently_open_count.toLocaleString()}</TableCell>
                                            <TableCell className="text-right">{formatMetric(row.first_response_sla_percent, 'percent')}</TableCell>
                                            <TableCell className="text-right">{formatMetric(row.resolution_sla_percent, 'percent')}</TableCell>
                                            <TableCell className="text-right">{row.rating_count.toLocaleString()}</TableCell>
                                            <TableCell className="text-right">{formatMetric(row.average_csat, 'rating')}</TableCell>
                                            <TableCell className="text-right">{formatMetric(row.low_rating_percent, 'percent')}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </SettingsLayout>
    );
}
