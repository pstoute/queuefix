import { Head, router, useForm } from '@inertiajs/react';
import { PageProps, User, TicketPriority } from '@/types';
import AgentLayout from '@/Layouts/AgentLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/Components/ui/select';
import { ArrowLeft } from 'lucide-react';

interface TicketCreateProps extends PageProps {
  agents: User[];
  priorities: { value: TicketPriority; label: string }[];
}

export default function TicketCreate({ agents, priorities }: TicketCreateProps) {
  const { data, setData, post, processing, errors } = useForm({
    customer_email: '',
    customer_name: '',
    subject: '',
    body: '',
    priority: 'normal' as TicketPriority,
    assigned_to: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/agent/tickets', {
      onSuccess: () => {
        // Will be redirected to the ticket detail page
      },
    });
  };

  return (
    <AgentLayout>
      <Head title="New Ticket" />

      <div className="container max-w-3xl mx-auto p-6">
        {/* Header */}
        <div className="mb-6">
          <div className="flex items-center gap-4 mb-4">
            <Button
              variant="ghost"
              size="icon"
              onClick={() => router.get('/agent/tickets')}
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <div>
              <h1 className="text-3xl font-bold tracking-tight">New Ticket</h1>
              <p className="text-muted-foreground mt-1">
                Create a new support ticket on behalf of a customer
              </p>
            </div>
          </div>
        </div>

        {/* Form */}
        <Card>
          <CardHeader>
            <CardTitle>Ticket Information</CardTitle>
            <CardDescription>
              Fill in the details below to create a new support ticket
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleSubmit} className="space-y-6">
              {/* Customer Information */}
              <div className="space-y-4">
                <div className="space-y-2">
                  <h3 className="text-sm font-semibold">Customer Information</h3>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="customer_name">
                      Customer Name <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id="customer_name"
                      value={data.customer_name}
                      onChange={(e) => setData('customer_name', e.target.value)}
                      placeholder="John Doe"
                      required
                    />
                    {errors.customer_name && (
                      <p className="text-sm text-destructive">{errors.customer_name}</p>
                    )}
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="customer_email">
                      Customer Email <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id="customer_email"
                      type="email"
                      value={data.customer_email}
                      onChange={(e) => setData('customer_email', e.target.value)}
                      placeholder="john@example.com"
                      required
                    />
                    {errors.customer_email && (
                      <p className="text-sm text-destructive">{errors.customer_email}</p>
                    )}
                  </div>
                </div>
              </div>

              {/* Ticket Details */}
              <div className="space-y-4">
                <div className="space-y-2">
                  <h3 className="text-sm font-semibold">Ticket Details</h3>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="subject">
                    Subject <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="subject"
                    value={data.subject}
                    onChange={(e) => setData('subject', e.target.value)}
                    placeholder="Brief description of the issue"
                    required
                  />
                  {errors.subject && (
                    <p className="text-sm text-destructive">{errors.subject}</p>
                  )}
                </div>

                <div className="space-y-2">
                  <Label htmlFor="body">
                    Message <span className="text-destructive">*</span>
                  </Label>
                  <Textarea
                    id="body"
                    value={data.body}
                    onChange={(e) => setData('body', e.target.value)}
                    placeholder="Detailed description of the issue..."
                    rows={8}
                    required
                  />
                  {errors.body && (
                    <p className="text-sm text-destructive">{errors.body}</p>
                  )}
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="priority">
                      Priority <span className="text-destructive">*</span>
                    </Label>
                    <Select
                      value={data.priority}
                      onValueChange={(value) => setData('priority', value as TicketPriority)}
                    >
                      <SelectTrigger id="priority">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {priorities.map((priority) => (
                          <SelectItem key={priority.value} value={priority.value}>
                            {priority.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {errors.priority && (
                      <p className="text-sm text-destructive">{errors.priority}</p>
                    )}
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="assigned_to">Assign To</Label>
                    <Select
                      value={data.assigned_to}
                      onValueChange={(value) => setData('assigned_to', value)}
                    >
                      <SelectTrigger id="assigned_to">
                        <SelectValue placeholder="Select agent (optional)" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="">Unassigned</SelectItem>
                        {agents.map((agent) => (
                          <SelectItem key={agent.id} value={agent.id}>
                            {agent.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {errors.assigned_to && (
                      <p className="text-sm text-destructive">{errors.assigned_to}</p>
                    )}
                  </div>
                </div>
              </div>

              {/* Actions */}
              <div className="flex items-center justify-end gap-4 pt-4 border-t">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => router.get('/agent/tickets')}
                  disabled={processing}
                >
                  Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                  {processing ? 'Creating...' : 'Create Ticket'}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      </div>
    </AgentLayout>
  );
}
