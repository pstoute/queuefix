<?php

use App\Models\Mailbox;
use App\Services\Email\MicrosoftGraphConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('Graph uses immutable provider IDs and acknowledges only after fetching', function () {
    Http::fake([
        'graph.microsoft.com/v1.0/me/messages*' => Http::response([
            'value' => [[
                'id' => 'immutable/id+123=',
                'subject' => 'Provider identity',
                'from' => ['emailAddress' => ['address' => 'customer@example.com', 'name' => 'Customer']],
                'toRecipients' => [['emailAddress' => ['address' => 'support@example.com']]],
                'body' => ['contentType' => 'text', 'content' => 'Please help'],
                'receivedDateTime' => '2026-09-05T12:00:00Z',
                'internetMessageHeaders' => [],
                'hasAttachments' => false,
                'internetMessageId' => null,
            ]],
        ]),
    ]);

    $mailbox = Mockery::mock(Mailbox::class)->makePartial();
    $mailbox->id = '00000000-0000-0000-0000-000000000001';
    $mailbox->shouldReceive('getDecryptedCredential')->with('access_token')->andReturn('access-token');
    $mailbox->shouldReceive('getDecryptedCredential')->with('refresh_token')->andReturn(null);
    $mailbox->shouldReceive('getDecryptedCredential')->with('token_expires_at')->andReturn((string) now()->addHour()->timestamp);

    $connector = new MicrosoftGraphConnector;

    expect($connector->connect($mailbox))->toBeTrue();
    $messages = $connector->fetchNewEmails(now());

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['provider_message_id'])->toBe('microsoft:immutable/id+123=')
        ->and($messages[0]['provider_remote_id'])->toBe('immutable/id+123=');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && ($query['$filter'] ?? null) === 'isRead eq false'
            && $request->hasHeader('Prefer', 'IdType="ImmutableId"');
    });

    expect($connector->acknowledge($messages[0]))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/me/messages/immutable%2Fid%2B123%3D')
        && $request->hasHeader('Prefer', 'IdType="ImmutableId"')
        && $request->data() === ['isRead' => true]);
});
