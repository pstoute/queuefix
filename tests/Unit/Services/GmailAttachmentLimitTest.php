<?php

use App\Services\Email\GmailConnector;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartBody;
use Google\Service\Gmail\Resource\UsersMessagesAttachments;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

test('Gmail preflights and hard caps the full provider response', function () {
    config([
        'attachments.max_body_bytes' => 10,
        'attachments.max_provider_message_bytes' => 1024,
    ]);
    $history = [];
    $service = gmailServiceWithResponses([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(
            gmailApiMessage('provider-stream-limit', 10),
            JSON_THROW_ON_ERROR,
        )),
        new Response(200, ['Content-Type' => 'application/json'], str_repeat('x', 2048)),
    ], $history);
    $connector = gmailConnectorWithService($service);

    $message = $connector->fetchEmail([
        'provider_message_id' => 'gmail:provider-stream-limit',
        'provider_remote_id' => 'provider-stream-limit',
    ]);

    parse_str($history[0]['request']->getUri()->getQuery(), $metadataQuery);
    parse_str($history[1]['request']->getUri()->getQuery(), $fullQuery);
    expect($message['body_text'])->toContain('omitted')
        ->and($message['attachment_rejection']['reason_code'])->toBe('message_too_large')
        ->and($history)->toHaveCount(2)
        ->and($metadataQuery['format'])->toBe('metadata')
        ->and($fullQuery['format'])->toBe('full')
        ->and($history[0]['options']['stream'])->toBeTrue()
        ->and($history[1]['options']['stream'])->toBeTrue();
});

test('Gmail public hydration admits an exact-limit body after preflight', function () {
    config([
        'attachments.max_body_bytes' => 10,
        'attachments.max_provider_message_bytes' => 4096,
    ]);
    $history = [];
    $service = gmailServiceWithResponses([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(
            gmailApiMessage('provider-exact-body', 10),
            JSON_THROW_ON_ERROR,
        )),
        new Response(200, ['Content-Type' => 'application/json'], json_encode(
            gmailApiMessage('provider-exact-body', 10, '1234567890'),
            JSON_THROW_ON_ERROR,
        )),
    ], $history);
    $connector = gmailConnectorWithService($service);

    $message = $connector->fetchEmail([
        'provider_message_id' => 'gmail:provider-exact-body',
        'provider_remote_id' => 'provider-exact-body',
    ]);

    expect($message['body_text'])->toBe('1234567890')
        ->and($message['body_html'])->toBeNull()
        ->and($message)->not->toHaveKey('attachment_rejection')
        ->and($history)->toHaveCount(2);
});

test('Gmail rejects attachment metadata over the count limit before content fetch', function () {
    config(['attachments.max_files_per_message' => 10]);
    $attachments = Mockery::mock(UsersMessagesAttachments::class);
    $attachments->shouldNotReceive('get');
    $service = Mockery::mock(Gmail::class);
    $service->users_messages_attachments = $attachments;
    $connector = gmailConnectorWithService($service);
    $payload = gmailPayload(range(1, 11));

    $result = invokeGmailAttachmentExtraction($connector, $payload);

    expect($result['attachments'])->toBe([])
        ->and($result['rejection']['reason_code'])->toBe('too_many_files')
        ->and($result['rejection']['reported_count'])->toBe(11);
});

test('Gmail fetches and decodes an attachment admitted by metadata', function () {
    $content = 'ordinary attachment';
    $responseBody = new MessagePartBody;
    $responseBody->setData(rtrim(strtr(base64_encode($content), '+/', '-_'), '='));
    $attachments = Mockery::mock(UsersMessagesAttachments::class);
    $attachments->shouldReceive('get')->once()->with('me', 'provider-1', 'attachment-1')->andReturn($responseBody);
    $service = Mockery::mock(Gmail::class);
    $service->users_messages_attachments = $attachments;
    $connector = gmailConnectorWithService($service);
    $payload = gmailPayload([1], strlen($content));

    $result = invokeGmailAttachmentExtraction($connector, $payload);

    expect($result['rejection'])->toBeNull()
        ->and($result['attachments'])->toHaveCount(1)
        ->and($result['attachments'][0]['content'])->toBe($content)
        ->and($result['attachments'][0]['size'])->toBe(strlen($content));
});

test('Gmail omits aggregate bodies over the configured byte limit', function () {
    config(['attachments.max_body_bytes' => 10]);
    $payload = gmailMultipartBody([
        gmailBodyPart('text/plain', '123456'),
        gmailBodyPart('text/html', '12345'),
    ]);

    $body = invokeGmailBodyExtraction(new GmailConnector, $payload);

    expect($body['text'])->toContain('omitted')
        ->and($body['html'])->toBeNull();
});

test('Gmail admits a body exactly at the configured byte limit', function () {
    config(['attachments.max_body_bytes' => 10]);

    $body = invokeGmailBodyExtraction(new GmailConnector, gmailBodyPart('text/plain', '1234567890'));

    expect($body)->toBe(['text' => '1234567890', 'html' => null]);
});

test('Gmail rechecks actual decoded bytes when provider metadata underreports them', function () {
    config(['attachments.max_body_bytes' => 10]);
    $payload = gmailMultipartBody([
        gmailBodyPart('text/plain', '123456', 1),
        gmailBodyPart('text/html', '12345', 1),
    ]);

    $body = invokeGmailBodyExtraction(new GmailConnector, $payload);

    expect($body['text'])->toContain('omitted')
        ->and($body['html'])->toBeNull();
});

test('Gmail admits MIME traversal exactly at the configured caps', function () {
    config([
        'attachments.max_body_bytes' => 10,
        'attachments.max_mime_depth' => 1,
        'attachments.max_mime_parts' => 3,
    ]);
    $payload = gmailMultipartBody([
        gmailBodyPart('text/plain', '12345'),
        gmailBodyPart('text/html', '67890'),
    ]);

    expect(invokeGmailBodyExtraction(new GmailConnector, $payload))
        ->toBe(['text' => '12345', 'html' => '67890']);
});

test('Gmail omits an over-limit externalized body before fetching it', function () {
    config(['attachments.max_body_bytes' => 10]);
    $attachments = Mockery::mock(UsersMessagesAttachments::class);
    $attachments->shouldNotReceive('get');
    $service = Mockery::mock(Gmail::class);
    $service->users_messages_attachments = $attachments;

    $body = invokeGmailBodyExtraction(
        gmailConnectorWithService($service),
        gmailExternalBodyPart('text/plain', 'external-body', 11),
        'provider-1',
    );

    expect($body['text'])->toContain('omitted');
});

test('Gmail fetches an externalized body exactly at the configured limit', function () {
    config(['attachments.max_body_bytes' => 10]);
    $responseBody = new MessagePartBody;
    $responseBody->setData(rtrim(strtr(base64_encode('1234567890'), '+/', '-_'), '='));
    $attachments = Mockery::mock(UsersMessagesAttachments::class);
    $attachments->shouldReceive('get')->once()->with('me', 'provider-1', 'external-body')->andReturn($responseBody);
    $service = Mockery::mock(Gmail::class);
    $service->users_messages_attachments = $attachments;

    $body = invokeGmailBodyExtraction(
        gmailConnectorWithService($service),
        gmailExternalBodyPart('text/plain', 'external-body', 10),
        'provider-1',
    );

    expect($body)->toBe(['text' => '1234567890', 'html' => null]);
});

test('Gmail does not treat a named external text attachment as message body', function () {
    config(['attachments.max_body_bytes' => 10]);
    $attachmentContent = 'evidence';
    $responseBody = new MessagePartBody;
    $responseBody->setData(rtrim(strtr(base64_encode($attachmentContent), '+/', '-_'), '='));
    $attachments = Mockery::mock(UsersMessagesAttachments::class);
    $attachments->shouldReceive('get')->once()->with('me', 'provider-1', 'text-attachment')->andReturn($responseBody);
    $service = Mockery::mock(Gmail::class);
    $service->users_messages_attachments = $attachments;
    $connector = gmailConnectorWithService($service);
    $payload = gmailMultipartBody([
        gmailBodyPart('text/plain', 'real body'),
        gmailNamedAttachmentPart('notes.txt', 'text/plain', 'text-attachment', strlen($attachmentContent)),
    ]);

    $body = invokeGmailBodyExtraction($connector, $payload, 'provider-1');
    $attachmentResult = invokeGmailAttachmentExtraction($connector, $payload);

    expect($body)->toBe(['text' => 'real body', 'html' => null])
        ->and($attachmentResult['attachments'])->toHaveCount(1)
        ->and($attachmentResult['attachments'][0]['content'])->toBe($attachmentContent);
});

test('Gmail bounds MIME traversal depth and part count', function () {
    config([
        'attachments.max_body_bytes' => 100,
        'attachments.max_mime_depth' => 1,
        'attachments.max_mime_parts' => 2,
    ]);
    $tooDeep = gmailMultipartBody([
        gmailMultipartBody([
            gmailBodyPart('text/plain', 'nested'),
        ]),
    ]);
    $tooMany = gmailMultipartBody([
        gmailBodyPart('text/plain', 'one'),
        gmailBodyPart('text/html', 'two'),
    ]);

    expect(invokeGmailBodyExtraction(new GmailConnector, $tooDeep)['text'])->toContain('omitted')
        ->and(invokeGmailBodyExtraction(new GmailConnector, $tooMany)['text'])->toContain('omitted');
});

function gmailConnectorWithService(Gmail $service): GmailConnector
{
    $connector = new GmailConnector;
    (new ReflectionClass($connector))->getProperty('service')->setValue($connector, $service);

    return $connector;
}

/**
 * @param  list<Response>  $responses
 *
 * @param-out list<array{request: \Psr\Http\Message\RequestInterface, response: ?\Psr\Http\Message\ResponseInterface, error: ?\Throwable, options: array<string, mixed>}>  $history
 */
function gmailServiceWithResponses(array $responses, array &$history): Gmail
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $client = new GoogleClient;
    $client->setAccessToken([
        'access_token' => 'access-token',
        'created' => time(),
        'expires_in' => 3600,
    ]);
    $client->setHttpClient(new GuzzleClient(['handler' => $stack]));

    return new Gmail($client);
}

/** @return array<string, mixed> */
function gmailApiMessage(string $id, int $sizeEstimate, ?string $bodyContent = null): array
{
    $body = ['size' => $bodyContent === null ? 0 : strlen($bodyContent)];
    if ($bodyContent !== null) {
        $body['data'] = rtrim(strtr(base64_encode($bodyContent), '+/', '-_'), '=');
    }

    return [
        'id' => $id,
        'sizeEstimate' => $sizeEstimate,
        'payload' => [
            'mimeType' => 'text/plain',
            'headers' => [
                ['name' => 'From', 'value' => 'Customer <customer@example.com>'],
                ['name' => 'To', 'value' => 'support@example.com'],
                ['name' => 'Subject', 'value' => 'Body policy'],
            ],
            'body' => $body,
        ],
    ];
}

/** @param iterable<int, int> $indexes */
function gmailPayload(iterable $indexes, int $size = 1): MessagePart
{
    $parts = [];

    foreach ($indexes as $index) {
        $body = new MessagePartBody;
        $body->setAttachmentId("attachment-{$index}");
        $body->setSize($size);
        $part = new MessagePart;
        $part->setFilename("evidence-{$index}.txt");
        $part->setMimeType('text/plain');
        $part->setBody($body);
        $parts[] = $part;
    }

    $payload = new MessagePart;
    $payload->setParts($parts);

    return $payload;
}

function gmailBodyPart(string $mimeType, string $content, ?int $declaredSize = null): MessagePart
{
    $body = new MessagePartBody;
    $body->setData(rtrim(strtr(base64_encode($content), '+/', '-_'), '='));
    $body->setSize($declaredSize ?? strlen($content));
    $part = new MessagePart;
    $part->setMimeType($mimeType);
    $part->setBody($body);

    return $part;
}

/** @param list<MessagePart> $parts */
function gmailMultipartBody(array $parts): MessagePart
{
    $body = new MessagePartBody;
    $body->setSize(0);
    $payload = new MessagePart;
    $payload->setMimeType('multipart/alternative');
    $payload->setBody($body);
    $payload->setParts($parts);

    return $payload;
}

function gmailExternalBodyPart(string $mimeType, string $attachmentId, int $declaredSize): MessagePart
{
    $body = new MessagePartBody;
    $body->setAttachmentId($attachmentId);
    $body->setSize($declaredSize);
    $part = new MessagePart;
    $part->setMimeType($mimeType);
    $part->setBody($body);

    return $part;
}

function gmailNamedAttachmentPart(string $filename, string $mimeType, string $attachmentId, int $declaredSize): MessagePart
{
    $part = gmailExternalBodyPart($mimeType, $attachmentId, $declaredSize);
    $part->setFilename($filename);

    return $part;
}

/** @return array<string, mixed> */
function invokeGmailAttachmentExtraction(GmailConnector $connector, MessagePart $payload): array
{
    $method = (new ReflectionClass($connector))->getMethod('extractAttachments');

    return $method->invoke($connector, $payload, 'provider-1');
}

/** @return array{text: ?string, html: ?string} */
function invokeGmailBodyExtraction(GmailConnector $connector, MessagePart $payload, ?string $messageId = null): array
{
    $method = (new ReflectionClass($connector))->getMethod('extractBody');

    return $method->invoke($connector, $payload, $messageId);
}
