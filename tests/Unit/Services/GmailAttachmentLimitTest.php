<?php

use App\Services\Email\GmailConnector;
use Google\Service\Gmail;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartBody;
use Google\Service\Gmail\Resource\UsersMessagesAttachments;

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

function gmailConnectorWithService(Gmail $service): GmailConnector
{
    $connector = new GmailConnector;
    (new ReflectionClass($connector))->getProperty('service')->setValue($connector, $service);

    return $connector;
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

/** @return array<string, mixed> */
function invokeGmailAttachmentExtraction(GmailConnector $connector, MessagePart $payload): array
{
    $method = (new ReflectionClass($connector))->getMethod('extractAttachments');

    return $method->invoke($connector, $payload, 'provider-1');
}
