# Simple Tickets Test Suite

This comprehensive test suite provides coverage for the Simple Tickets application using Pest PHP testing framework with PostgreSQL.

## Test Structure

### Feature Tests

Feature tests verify the application's behavior from an end-user perspective, testing complete workflows through HTTP requests.

#### Authentication Tests
**File:** `/tests/Feature/Auth/AuthenticationTest.php`

- Login page rendering
- Login with valid/invalid credentials
- Logout functionality
- OAuth redirects (Google, Microsoft)
- Magic link sending and verification
- Signed URL expiration

#### Agent Ticket Controller Tests
**File:** `/tests/Feature/Agent/TicketControllerTest.php`

- Ticket index page rendering
- Authentication guards
- Filtering by status, priority, assignee
- Search functionality (subject, ticket number, customer)
- Ticket creation and viewing
- Replying to tickets
- Adding internal notes
- Status changes
- Ticket assignment/unassignment
- Ticket merging with message and tag syncing

#### Agent Dashboard Tests
**File:** `/tests/Feature/Agent/DashboardTest.php`

- Dashboard rendering with statistics
- Correct ticket counts by status
- Authentication requirements

#### Tag Management Tests
**File:** `/tests/Feature/Agent/TagTest.php`

- Listing tags
- Creating tags with validation
- Attaching tags to tickets
- Detaching tags from tickets
- Duplicate tag prevention

#### Canned Response Tests
**File:** `/tests/Feature/Agent/CannedResponseTest.php`

- Listing canned responses
- Creating/updating/deleting canned responses
- Variable rendering
- Validation requirements

#### Mailbox Settings Tests
**File:** `/tests/Feature/Settings/MailboxTest.php`

- Admin access control
- Creating mailboxes (IMAP, Gmail, Microsoft)
- Updating mailbox settings
- Deleting mailboxes
- Email uniqueness validation
- Required field validation

#### General Settings Tests
**File:** `/tests/Feature/Settings/GeneralSettingsTest.php`

- Settings page rendering
- Updating general settings
- Authentication requirements

#### User Management Tests
**File:** `/tests/Feature/Settings/UserManagementTest.php`

- Listing users
- Inviting users (Agent/Admin roles)
- Updating user roles
- Email uniqueness validation
- Required field validation

#### SLA Policy Tests
**File:** `/tests/Feature/Settings/SlaTest.php`

- Listing SLA policies
- Creating SLA policies for different priorities
- Updating SLA policies
- Deleting SLA policies
- Required field validation

#### Customer Portal Tests
**File:** `/tests/Feature/Customer/CustomerPortalTest.php`

- Customer login page rendering
- Magic link authentication
- Auto-creation of customers
- Viewing own tickets
- Access control (cannot view others' tickets)
- Replying to tickets
- Logout functionality

### Unit Tests

Unit tests verify individual components in isolation, often using mocking for dependencies.

#### TicketService Tests
**File:** `/tests/Unit/Services/TicketServiceTest.php`

- Ticket number auto-generation (ST-1, ST-2, etc.)
- Sequential ticket numbering
- Mailbox and assignee assignment
- Message addition updates last_activity_at
- Agent replies record first SLA response
- Internal notes don't record SLA response
- Status updates trigger SLA handlers
- Resolved/Closed status records resolution
- Ticket assignment/unassignment
- Ticket merging moves messages and syncs tags
- Next ticket number calculation

#### SlaService Tests
**File:** `/tests/Unit/Services/SlaServiceTest.php`

- Timer initialization with matching policy
- No timer when no policy matches
- Inactive policies are ignored
- Recording first response (met vs breached)
- Recording resolution (met vs breached)
- SLA pause on Pending/OnHold status
- SLA resume extends due dates by paused time
- Breach detection for first response and resolution
- Paused timers excluded from breach detection
- Paused time excluded from calculations

#### EmailProcessorService Tests
**File:** `/tests/Unit/Services/EmailProcessorServiceTest.php`

- Creating tickets from new senders
- Creating customers from emails
- Email prefix fallback for customer names
- Matching existing tickets by:
  - In-Reply-To header
  - References header (string and array)
  - Subject line pattern [ST-XXX]
- Reopening resolved/closed tickets
- Attachment processing
- Default subject handling
- HTML-only email handling
- Outbound header generation (Subject, In-Reply-To, References)
- Customer email case-insensitivity
- Existing customer reuse

#### Ticket Model Tests
**File:** `/tests/Unit/Models/TicketTest.php`

- Auto-generation of ticket_number
- Sequential ticket numbering
- Custom ticket numbers
- Automatic last_activity_at setting
- Relationships:
  - Customer (belongsTo)
  - Assignee (belongsTo User)
  - Mailbox (belongsTo)
  - Messages (hasMany)
  - SlaTimer (hasOne)
  - Tags (belongsToMany)
- Enum casting (TicketStatus, TicketPriority)
- DateTime casting
- UUID primary key
- Fillable attributes
- Query scopes by status, priority, assignee, customer

## Database Factories

All models have comprehensive factories for easy test data generation:

### Core Factories

- **UserFactory**: Creates agents and admins
- **CustomerFactory**: Creates customers with contact info
- **TicketFactory**: Creates tickets with various states (pending, resolved, closed, high priority, urgent)
- **MessageFactory**: Creates messages from agents or customers, including internal notes
- **TagFactory**: Creates tags with colors
- **CannedResponseFactory**: Creates canned responses
- **MailboxFactory**: Creates mailboxes (IMAP, Gmail, Microsoft)
- **SlaPolicyFactory**: Creates SLA policies for different priorities
- **SlaTimerFactory**: Creates SLA timers with various states (paused, breached)

### Factory Usage Examples

```php
// Create a ticket with customer
$ticket = Ticket::factory()->create();

// Create an urgent ticket
$ticket = Ticket::factory()->urgent()->create();

// Create a resolved ticket
$ticket = Ticket::factory()->resolved()->create();

// Create a ticket with assignee
$ticket = Ticket::factory()->withAssignee()->create();

// Create an admin user
$admin = User::factory()->admin()->create();

// Create a message from an agent
$message = Message::factory()->fromAgent()->create();

// Create an internal note
$note = Message::factory()->internalNote()->create();
```

## Running Tests

### Run All Tests
```bash
php artisan test
```

### Run Specific Test Suite
```bash
# Feature tests only
php artisan test --testsuite=Feature

# Unit tests only
php artisan test --testsuite=Unit
```

### Run Specific Test File
```bash
php artisan test tests/Feature/Agent/TicketControllerTest.php
php artisan test tests/Unit/Services/SlaServiceTest.php
```

### Run with Coverage
```bash
php artisan test --coverage
```

### Run Specific Test
```bash
php artisan test --filter "creating a ticket generates ticket number"
```

## Test Configuration

The test suite is configured in `/tests/Pest.php`:
- Uses `RefreshDatabase` trait to reset database between tests
- Uses PostgreSQL as the test database
- Applies to both Feature and Unit test directories

## Test Helpers and Best Practices

### Authentication in Tests
```php
// Acting as a user
actingAs($user);

// Acting as a customer
actingAs($customer, 'customer');

// Creating an authenticated admin
$admin = User::factory()->admin()->create();
actingAs($admin);
```

### Inertia Assertions
```php
get(route('agent.tickets.index'))
    ->assertInertia(fn ($page) => $page
        ->component('Agent/Tickets/Index')
        ->has('tickets')
        ->where('filters.status', 'open')
    );
```

### Database Assertions
```php
$this->assertDatabaseHas('tickets', [
    'subject' => 'Test ticket',
    'status' => TicketStatus::Open->value,
]);

$this->assertDatabaseMissing('tickets', [
    'id' => $ticket->id,
]);

$this->assertDatabaseCount('tickets', 5);
```

### Pest Expectations
```php
expect($ticket->ticket_number)->toStartWith('ST-');
expect($ticket->tags)->toHaveCount(3);
expect($timer->paused_at)->toBeNull();
expect($ticket->status)->toBe(TicketStatus::Open);
```

### Mocking Services
```php
$this->slaService = Mockery::mock(SlaService::class);
$this->slaService->shouldReceive('initializeTimer')->once()->andReturnNull();
$this->slaService->shouldReceive('recordFirstResponse')
    ->once()
    ->with(Mockery::on(fn($t) => $t->id === $ticket->id));
```

## Coverage Summary

### Feature Tests: 10 Files
- **Auth**: 11 tests covering login, OAuth, magic links
- **Agent/Tickets**: 24 tests covering full ticket lifecycle
- **Agent/Dashboard**: 3 tests covering dashboard display
- **Agent/Tags**: 6 tests covering tag management
- **Agent/CannedResponse**: 6 tests covering canned responses
- **Settings/Mailbox**: 11 tests covering mailbox configuration
- **Settings/General**: 3 tests covering general settings
- **Settings/Users**: 7 tests covering user management
- **Settings/SLA**: 8 tests covering SLA policies
- **Customer/Portal**: 11 tests covering customer portal

**Total Feature Tests: ~90 tests**

### Unit Tests: 4 Files
- **TicketService**: 19 tests covering ticket operations
- **SlaService**: 21 tests covering SLA logic
- **EmailProcessorService**: 21 tests covering email processing
- **Ticket Model**: 24 tests covering model behavior

**Total Unit Tests: ~85 tests**

## Multi-Tenant Testing Strategy

While this is a single-tenant application, the tests follow best practices that would apply to multi-tenant systems:

1. **Data Isolation**: Each test uses `RefreshDatabase` to ensure clean state
2. **Authorization Testing**: Tests verify users can only access their allowed resources
3. **Customer Portal Isolation**: Customer tests verify they cannot access other customers' data
4. **Permission Testing**: Tests verify role-based access (Admin vs Agent)
5. **Scope Testing**: Queries are tested to ensure proper filtering

## Continuous Integration

These tests are designed to run in CI/CD pipelines:
- All tests are deterministic and don't rely on external services
- Database is reset between tests
- No hardcoded time dependencies (uses Carbon for time manipulation)
- Parallel execution safe (each test is isolated)

## Contributing

When adding new features:
1. Write tests first (TDD approach)
2. Ensure both positive and negative test cases
3. Test edge cases and error conditions
4. Use factories for test data
5. Keep tests focused and descriptive
6. Follow Pest syntax conventions
