<?php

namespace Database\Seeders;

use App\Enums\MailboxType;
use App\Enums\MessageType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\CannedResponse;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Setting;
use App\Models\SlaPolicy;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create admin user
        $admin = User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        // Create agent users
        $agents = collect();
        $agents->push($admin);

        $agent1 = User::factory()->create([
            'name' => 'Sarah Chen',
            'email' => 'sarah@example.com',
        ]);
        $agents->push($agent1);

        $agent2 = User::factory()->create([
            'name' => 'Marcus Johnson',
            'email' => 'marcus@example.com',
        ]);
        $agents->push($agent2);

        $agent3 = User::factory()->create([
            'name' => 'Emily Rodriguez',
            'email' => 'emily@example.com',
        ]);
        $agents->push($agent3);

        // Create customers
        $customers = collect([
            Customer::create(['name' => 'John Smith', 'email' => 'john@acmecorp.com', 'company' => 'Acme Corp']),
            Customer::create(['name' => 'Jane Doe', 'email' => 'jane@techstartup.io', 'company' => 'Tech Startup']),
            Customer::create(['name' => 'Bob Wilson', 'email' => 'bob@bigenterprise.com', 'company' => 'Big Enterprise']),
            Customer::create(['name' => 'Alice Brown', 'email' => 'alice@freelancer.dev', 'company' => null]),
            Customer::create(['name' => 'Charlie Davis', 'email' => 'charlie@smallbiz.co', 'company' => 'Small Biz Co']),
            Customer::create(['name' => 'Diana Miller', 'email' => 'diana@designstudio.com', 'company' => 'Design Studio']),
            Customer::create(['name' => 'Eve Thompson', 'email' => 'eve@consulting.group', 'company' => 'Consulting Group']),
            Customer::create(['name' => 'Frank Garcia', 'email' => 'frank@startup.xyz', 'company' => 'Startup XYZ']),
        ]);

        // Create tags
        $tags = collect([
            Tag::create(['name' => 'Bug', 'color' => '#ef4444']),
            Tag::create(['name' => 'Feature Request', 'color' => '#8b5cf6']),
            Tag::create(['name' => 'Question', 'color' => '#3b82f6']),
            Tag::create(['name' => 'Billing', 'color' => '#f59e0b']),
            Tag::create(['name' => 'Account', 'color' => '#10b981']),
            Tag::create(['name' => 'Urgent', 'color' => '#dc2626']),
            Tag::create(['name' => 'Documentation', 'color' => '#6366f1']),
        ]);

        // Create a demo mailbox
        Mailbox::create([
            'name' => 'Support',
            'email' => 'support@example.com',
            'type' => MailboxType::Imap,
            'credentials' => encrypt([
                'username' => 'support@example.com',
                'password' => 'demo-password',
            ]),
            'incoming_settings' => [
                'host' => 'mail.example.com',
                'port' => 993,
                'encryption' => 'ssl',
            ],
            'outgoing_settings' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
            ],
            'department' => 'Support',
            'polling_interval' => 2,
            'is_active' => true,
        ]);

        // Create SLA policies
        SlaPolicy::create([
            'name' => 'Urgent SLA',
            'priority' => TicketPriority::Urgent,
            'first_response_hours' => 0.5,
            'resolution_hours' => 4,
            'is_active' => true,
        ]);

        SlaPolicy::create([
            'name' => 'High Priority SLA',
            'priority' => TicketPriority::High,
            'first_response_hours' => 1,
            'resolution_hours' => 8,
            'is_active' => true,
        ]);

        SlaPolicy::create([
            'name' => 'Normal SLA',
            'priority' => TicketPriority::Normal,
            'first_response_hours' => 4,
            'resolution_hours' => 24,
            'is_active' => true,
        ]);

        SlaPolicy::create([
            'name' => 'Low Priority SLA',
            'priority' => TicketPriority::Low,
            'first_response_hours' => 8,
            'resolution_hours' => 72,
            'is_active' => true,
        ]);

        // Create canned responses
        CannedResponse::create([
            'title' => 'Welcome / First Response',
            'body' => "Hi {{customer_name}},\n\nThank you for reaching out to us! I've received your request ({{ticket_number}}) and I'm looking into it now.\n\nI'll get back to you as soon as possible.\n\nBest regards,\n{{agent_name}}",
            'created_by' => $admin->id,
        ]);

        CannedResponse::create([
            'title' => 'Need More Information',
            'body' => "Hi {{customer_name}},\n\nThank you for contacting us about {{ticket_number}}. To help resolve this, could you please provide:\n\n1. Steps to reproduce the issue\n2. Any error messages you're seeing\n3. Your browser/device information\n\nThis will help us investigate more efficiently.\n\nBest regards,\n{{agent_name}}",
            'created_by' => $admin->id,
        ]);

        CannedResponse::create([
            'title' => 'Issue Resolved',
            'body' => "Hi {{customer_name}},\n\nGreat news! The issue reported in {{ticket_number}} has been resolved.\n\nPlease let us know if you experience any further problems.\n\nBest regards,\n{{agent_name}}",
            'created_by' => $admin->id,
        ]);

        CannedResponse::create([
            'title' => 'Escalating to Engineering',
            'body' => "Hi {{customer_name}},\n\nI've escalated your ticket ({{ticket_number}}) to our engineering team for further investigation. They'll be looking into this as a priority.\n\nWe'll keep you updated on the progress.\n\nBest regards,\n{{agent_name}}",
            'created_by' => $admin->id,
        ]);

        // Create sample tickets with messages
        $ticketData = [
            [
                'subject' => 'Cannot login to my account',
                'status' => TicketStatus::Open,
                'priority' => TicketPriority::High,
                'customer' => $customers[0],
                'assignee' => $agent1,
                'tags' => [$tags[4]->id, $tags[5]->id],
                'messages' => [
                    ['body' => "Hi, I've been trying to log into my account for the past hour but keep getting an 'Invalid credentials' error. I'm sure my password is correct. Can you help?", 'sender_type' => Customer::class, 'type' => MessageType::Reply],
                    ['body' => "Hi John, I'm sorry to hear about the login issue. Let me check your account status right away.", 'sender_type' => User::class, 'type' => MessageType::Reply],
                    ['body' => "Checked the logs - looks like the account got locked after multiple failed attempts from a different IP. Might be a brute force attempt.", 'sender_type' => User::class, 'type' => MessageType::InternalNote],
                ],
            ],
            [
                'subject' => 'Feature request: Dark mode for dashboard',
                'status' => TicketStatus::Open,
                'priority' => TicketPriority::Normal,
                'customer' => $customers[1],
                'assignee' => null,
                'tags' => [$tags[1]->id],
                'messages' => [
                    ['body' => "Would love to see a dark mode option for the dashboard. Working late at night and the bright white background is not great for my eyes. Many modern apps have this now.", 'sender_type' => Customer::class, 'type' => MessageType::Reply],
                ],
            ],
            [
                'subject' => 'Billing discrepancy on last invoice',
                'status' => TicketStatus::Pending,
                'priority' => TicketPriority::High,
                'customer' => $customers[2],
                'assignee' => $agent2,
                'tags' => [$tags[3]->id],
                'messages' => [
                    ['body' => "I noticed that my last invoice (INV-2025-0342) shows a charge of \$299 but my plan is \$199/month. Could you look into this?", 'sender_type' => Customer::class, 'type' => MessageType::Reply],
                    ['body' => "Hi Bob, thanks for flagging this. I can see the charge and I'm looking into it now. It appears there may have been a pro-rated charge from a mid-month upgrade. Let me verify the details.", 'sender_type' => User::class, 'type' => MessageType::Reply],
                    ['body' => "Waiting for finance team to confirm the pro-rated amount.", 'sender_type' => User::class, 'type' => MessageType::InternalNote],
                ],
            ],
            [
                'subject' => 'API rate limiting too aggressive',
                'status' => TicketStatus::OnHold,
                'priority' => TicketPriority::Normal,
                'customer' => $customers[3],
                'assignee' => $agent1,
                'tags' => [$tags[0]->id, $tags[1]->id],
                'messages' => [
                    ['body' => "I'm hitting rate limits way too often with the API. I'm only making about 100 requests per minute but getting 429 errors. The docs say the limit is 1000/min.", 'sender_type' => Customer::class, 'type' => MessageType::Reply],
                    ['body' => "Hi Alice, I've checked your API usage logs and I can see the 429 errors. Let me escalate this to our engineering team as it looks like the rate limiter might be misconfigured for your tier.", 'sender_type' => User::class, 'type' => MessageType::Reply],
                    ['body' => "Engineering is investigating. They suspect a recent deployment may have changed the rate limit config.", 'sender_type' => User::class, 'type' => MessageType::InternalNote],
                ],
            ],
            [
                'subject' => 'How to set up SSO?',
                'status' => TicketStatus::Resolved,
                'priority' => TicketPriority::Low,
                'customer' => $customers[4],
                'assignee' => $agent3,
                'tags' => [$tags[2]->id, $tags[6]->id],
                'messages' => [
                    ['body' => "We're looking to set up SSO for our team. Can you point me to the documentation for SAML configuration?", 'sender_type' => Customer::class, 'type' => MessageType::Reply],
                    ['body' => "Hi Charlie! Great question. You can find our SSO setup guide here: docs.example.com/sso-setup. The guide covers SAML 2.0 configuration with all major identity providers. Let me know if you need any help.", 'sender_type' => User::class, 'type' => MessageType::Reply],
                    ['body' => "Perfect, that's exactly what I needed. Got it set up with Okta. Thanks!", 'sender_type' => Customer::class, 'type' => MessageType::Reply],
                ],
            ],
            [
                'subject' => 'Export data in CSV format not working',
                'status' => TicketStatus::Open,
                'priority' => TicketPriority::Urgent,
                'customer' => $customers[5],
                'assignee' => $agent2,
                'tags' => [$tags[0]->id, $tags[5]->id],
                'messages' => [
                    ['body' => "The CSV export feature is completely broken. When I try to export our project data, the download starts but the file is empty (0 bytes). We need this urgently for a client presentation tomorrow.", 'sender_type' => Customer::class, 'type' => MessageType::Reply],
                    ['body' => "Hi Diana, I'm really sorry about this. I'm marking this as urgent and looking into it immediately.", 'sender_type' => User::class, 'type' => MessageType::Reply],
                ],
            ],
            [
                'subject' => 'Webhook integration with Slack',
                'status' => TicketStatus::Open,
                'priority' => TicketPriority::Normal,
                'customer' => $customers[6],
                'assignee' => null,
                'tags' => [$tags[1]->id, $tags[2]->id],
                'messages' => [
                    ['body' => "Is there a way to set up webhooks that post to our Slack channel whenever a specific event occurs? We'd like real-time notifications for new signups.", 'sender_type' => Customer::class, 'type' => MessageType::Reply],
                ],
            ],
            [
                'subject' => 'Downgrade plan request',
                'status' => TicketStatus::Closed,
                'priority' => TicketPriority::Low,
                'customer' => $customers[7],
                'assignee' => $agent3,
                'tags' => [$tags[3]->id, $tags[4]->id],
                'messages' => [
                    ['body' => "Hi, I'd like to downgrade from the Pro plan to the Basic plan effective next billing cycle. Can you process this?", 'sender_type' => Customer::class, 'type' => MessageType::Reply],
                    ['body' => "Hi Frank, I've processed your downgrade request. It will take effect on your next billing date (Feb 15). You'll continue to have Pro features until then.", 'sender_type' => User::class, 'type' => MessageType::Reply],
                    ['body' => "Great, thank you for the quick turnaround!", 'sender_type' => Customer::class, 'type' => MessageType::Reply],
                ],
            ],
        ];

        foreach ($ticketData as $index => $data) {
            $ticket = Ticket::create([
                'subject' => $data['subject'],
                'status' => $data['status'],
                'priority' => $data['priority'],
                'customer_id' => $data['customer']->id,
                'assigned_to' => $data['assignee']?->id,
                'last_activity_at' => now()->subHours(rand(0, 72)),
            ]);

            if (! empty($data['tags'])) {
                $ticket->tags()->sync($data['tags']);
            }

            foreach ($data['messages'] as $msgIndex => $msg) {
                $senderId = $msg['sender_type'] === Customer::class
                    ? $data['customer']->id
                    : ($data['assignee']?->id ?? $admin->id);

                Message::create([
                    'ticket_id' => $ticket->id,
                    'sender_type' => $msg['sender_type'],
                    'sender_id' => $senderId,
                    'type' => $msg['type'],
                    'body_text' => $msg['body'],
                    'body_html' => '<p>' . nl2br(e($msg['body'])) . '</p>',
                    'created_at' => now()->subHours(72 - ($index * 8) - ($msgIndex * 2)),
                    'updated_at' => now()->subHours(72 - ($index * 8) - ($msgIndex * 2)),
                ]);
            }
        }

        // Create default settings
        Setting::create(['key' => 'app_name', 'value' => 'QueueFix', 'group' => 'general']);
        Setting::create(['key' => 'app_url', 'value' => 'http://localhost:8000', 'group' => 'general']);
        Setting::create(['key' => 'timezone', 'value' => 'UTC', 'group' => 'general']);
        Setting::create(['key' => 'default_language', 'value' => 'en', 'group' => 'general']);
        Setting::create(['key' => 'ticket_prefix', 'value' => 'QF', 'group' => 'general']);
        Setting::create(['key' => 'accent_color', 'value' => '#6366f1', 'group' => 'appearance']);

        // Ticket counter tracks the last assigned ticket number for atomic generation
        Setting::create(['key' => 'ticket_counter', 'value' => '8', 'group' => 'system']);
    }
}
