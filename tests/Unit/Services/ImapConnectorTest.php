<?php

namespace Tests\Support {
    final class ImapFunctionState
    {
        /** @var list<int> */
        public static array $fetchBodyFlags = [];

        public static string $searchCriteria = '';

        public static int $seenWrites = 0;

        public static int $seenFlags = 0;

        public static string $seenSequence = '';

        public static int $attachmentCount = 1;

        public static int $attachmentBytes = 18;

        public static function reset(): void
        {
            self::$fetchBodyFlags = [];
            self::$searchCriteria = '';
            self::$seenWrites = 0;
            self::$seenFlags = 0;
            self::$seenSequence = '';
            self::$attachmentCount = 1;
            self::$attachmentBytes = strlen('attachment-content');
        }
    }
}

namespace App\Services\Email {
    use Tests\Support\ImapFunctionState;

    function imap_search(mixed $connection, string $criteria): array
    {
        ImapFunctionState::$searchCriteria = $criteria;

        return [1];
    }

    function imap_uid(mixed $connection, int $emailNumber): int
    {
        return 456;
    }

    function imap_msgno(mixed $connection, int $uid): int
    {
        return $uid === 456 ? 1 : 0;
    }

    function imap_headerinfo(mixed $connection, int $emailNumber): object
    {
        return (object) [
            'from' => [(object) ['mailbox' => 'customer', 'host' => 'example.com', 'personal' => 'Customer']],
            'to' => [(object) ['mailbox' => 'support', 'host' => 'example.com']],
            'subject' => 'Unread message',
            'date' => 'Sat, 05 Sep 2026 12:00:00 +0000',
        ];
    }

    function imap_fetchstructure(mixed $connection, int $emailNumber): object
    {
        $parts = [
            (object) [
                'type' => 0,
                'subtype' => 'PLAIN',
                'encoding' => 0,
                'ifdisposition' => 0,
            ],
        ];

        foreach (range(1, ImapFunctionState::$attachmentCount) as $index) {
            $parts[] = (object) [
                'type' => 3,
                'subtype' => 'PDF',
                'encoding' => 0,
                'ifdisposition' => 1,
                'disposition' => 'attachment',
                'bytes' => ImapFunctionState::$attachmentBytes,
                'ifdparameters' => 1,
                'dparameters' => [(object) ['attribute' => 'filename', 'value' => "evidence-{$index}.pdf"]],
            ];
        }

        return (object) [
            'type' => 1,
            'parts' => $parts,
        ];
    }

    function imap_fetchbody(mixed $connection, int $emailNumber, string $section, int $flags = 0): string
    {
        ImapFunctionState::$fetchBodyFlags[] = $flags;

        return $section === '1' ? 'Message body' : 'attachment-content';
    }

    function imap_fetchheader(mixed $connection, int $emailNumber): string
    {
        return "Message-ID: <imap-provider-test@example.com>\r\n";
    }

    function imap_utf8(string $value): string
    {
        return $value;
    }

    function imap_setflag_full(mixed $connection, string $sequence, string $flag, int $options = 0): bool
    {
        ImapFunctionState::$seenWrites++;
        ImapFunctionState::$seenSequence = $sequence;
        ImapFunctionState::$seenFlags = $options;

        return true;
    }

    function imap_close(mixed $connection): bool
    {
        return true;
    }
}

namespace {
    use App\Models\Mailbox;
    use App\Services\Email\ImapConnector;
    use Tests\Support\ImapFunctionState;

    beforeEach(function () {
        if (! defined('FT_PEEK')) {
            define('FT_PEEK', 2);
        }

        if (! defined('ST_UID')) {
            define('ST_UID', 1);
        }

        ImapFunctionState::reset();
    });

    test('fetching IMAP bodies preserves Unseen until explicit UID acknowledgement', function () {
        $mailbox = Mailbox::factory()->create();
        $connector = new ImapConnector;
        $reflection = new ReflectionClass($connector);

        foreach ([
            'connection' => new stdClass,
            'mailbox' => $mailbox,
            'uidValidity' => 123,
        ] as $propertyName => $value) {
            $property = $reflection->getProperty($propertyName);
            $property->setValue($connector, $value);
        }

        $references = iterator_to_array($connector->fetchNewEmailReferences(now()));
        $messages = [$connector->fetchEmail($references[0])];

        expect($references)->toHaveCount(1)
            ->and($references[0]['provider_message_id'])->toBe('imap:INBOX:123:456')
            ->and($messages)->toHaveCount(1)
            ->and($messages[0]['provider_message_id'])->toBe('imap:INBOX:123:456')
            ->and($messages[0]['provider_remote_id'])->toBe('456')
            ->and(ImapFunctionState::$searchCriteria)->toBe('UNSEEN')
            ->and(ImapFunctionState::$fetchBodyFlags)->toBe([FT_PEEK, FT_PEEK])
            ->and(ImapFunctionState::$seenWrites)->toBe(0);

        $uidValidity = $reflection->getProperty('uidValidity');
        $uidValidity->setValue($connector, 124);

        expect($connector->acknowledge($messages[0]))->toBeFalse()
            ->and(ImapFunctionState::$seenWrites)->toBe(0);

        $uidValidity->setValue($connector, 123);

        expect($connector->acknowledge($messages[0]))->toBeTrue()
            ->and(ImapFunctionState::$seenWrites)->toBe(1)
            ->and(ImapFunctionState::$seenSequence)->toBe('456')
            ->and(ImapFunctionState::$seenFlags)->toBe(ST_UID);
    });

    test('IMAP rejects attachment metadata over the count limit before fetching attachment content', function () {
        config(['attachments.max_files_per_message' => 10]);
        ImapFunctionState::$attachmentCount = 11;
        $mailbox = Mailbox::factory()->create();
        $connector = new ImapConnector;
        $reflection = new ReflectionClass($connector);

        foreach ([
            'connection' => new stdClass,
            'mailbox' => $mailbox,
            'uidValidity' => 123,
        ] as $propertyName => $value) {
            $reflection->getProperty($propertyName)->setValue($connector, $value);
        }

        $references = iterator_to_array($connector->fetchNewEmailReferences());
        $message = $connector->fetchEmail($references[0]);

        expect($message['attachments'])->toBe([])
            ->and($message['attachment_rejection']['reason_code'])->toBe('too_many_files')
            ->and($message['attachment_rejection']['reported_count'])->toBe(11)
            ->and(ImapFunctionState::$fetchBodyFlags)->toBe([FT_PEEK]);
    });
}
