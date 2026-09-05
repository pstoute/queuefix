<?php

namespace Tests\Support {
    final class ImapFunctionState
    {
        /** @var list<int> */
        public static array $fetchBodyFlags = [];

        /** @var list<string> */
        public static array $fetchBodySections = [];

        /** @var array<array-key, string|false> */
        public static array $bodyBySection = [];

        public static ?object $structure = null;

        public static string $overviewSequence = '';

        public static int $messageCount = 1;

        /** @var list<object>|false */
        public static array|false $overviewRows = [];

        public static int $seenWrites = 0;

        public static int $seenFlags = 0;

        public static string $seenSequence = '';

        public static int $attachmentCount = 1;

        public static int $attachmentBytes = 18;

        public static function reset(): void
        {
            self::$fetchBodyFlags = [];
            self::$fetchBodySections = [];
            self::$bodyBySection = [];
            self::$structure = null;
            self::$overviewSequence = '';
            self::$messageCount = 1;
            self::$overviewRows = [(object) ['msgno' => 1, 'uid' => 456, 'seen' => 0]];
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

    function imap_num_msg(mixed $connection): int
    {
        return ImapFunctionState::$messageCount;
    }

    /** @return list<object>|false */
    function imap_fetch_overview(mixed $connection, string $sequence, int $flags = 0): array|false
    {
        ImapFunctionState::$overviewSequence = $sequence;

        return ImapFunctionState::$overviewRows;
    }

    function imap_uid(mixed $connection, int $emailNumber): int
    {
        return 455 + $emailNumber;
    }

    function imap_msgno(mixed $connection, int $uid): int
    {
        return $uid > 455 ? $uid - 455 : 0;
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
        if (ImapFunctionState::$structure !== null) {
            return ImapFunctionState::$structure;
        }

        $parts = [
            (object) [
                'type' => 0,
                'subtype' => 'PLAIN',
                'encoding' => 0,
                'ifdisposition' => 0,
                'bytes' => strlen('Message body'),
            ],
        ];

        for ($index = 1; $index <= ImapFunctionState::$attachmentCount; $index++) {
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

    function imap_fetchbody(mixed $connection, int $emailNumber, string $section, int $flags = 0): string|false
    {
        ImapFunctionState::$fetchBodyFlags[] = $flags;
        ImapFunctionState::$fetchBodySections[] = $section;

        return ImapFunctionState::$bodyBySection[$section]
            ?? ($section === '1' ? 'Message body' : 'attachment-content');
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
            ->and(ImapFunctionState::$overviewSequence)->toBe('1:1')
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

    test('IMAP rotates through bounded server-side overview windows', function () {
        config(['inbound.poll_batch_size' => 3]);
        ImapFunctionState::$messageCount = 100;
        ImapFunctionState::$overviewRows = array_map(
            fn (int $messageNumber): object => (object) [
                'msgno' => $messageNumber,
                'uid' => 455 + $messageNumber,
                'seen' => 0,
            ],
            range(1, 12),
        );
        $connector = imapConnectorForTest();
        $references = iterator_to_array($connector->fetchNewEmailReferences());

        expect($references)->toHaveCount(12)
            ->and($references[0]['provider_message_id'])->toBe('imap:INBOX:123:456')
            ->and($references[11]['provider_message_id'])->toBe('imap:INBOX:123:467')
            ->and(ImapFunctionState::$overviewSequence)->toBe('1:12')
            ->and(Mailbox::query()->sole()->imap_poll_cursor)->toBe(13);

        ImapFunctionState::$overviewRows = array_map(
            fn (int $messageNumber): object => (object) [
                'msgno' => $messageNumber,
                'uid' => 455 + $messageNumber,
                'seen' => 0,
            ],
            range(13, 24),
        );
        iterator_to_array($connector->fetchNewEmailReferences());

        expect(ImapFunctionState::$overviewSequence)->toBe('13:24')
            ->and(Mailbox::query()->sole()->imap_poll_cursor)->toBe(25);
    });

    test('IMAP bounded overview windows filter seen and malformed rows', function () {
        ImapFunctionState::$messageCount = 4;
        ImapFunctionState::$overviewRows = [
            (object) ['msgno' => 1, 'uid' => 456, 'seen' => 1],
            (object) ['msgno' => 2, 'uid' => 457, 'seen' => 0],
            (object) ['msgno' => 0, 'uid' => 458, 'seen' => 0],
            (object) ['msgno' => 4, 'uid' => null, 'seen' => 0],
        ];

        $references = iterator_to_array(imapConnectorForTest()->fetchNewEmailReferences());

        expect(ImapFunctionState::$overviewSequence)->toBe('1:4')
            ->and($references)->toHaveCount(2)
            ->and($references[0]['provider_remote_id'])->toBe('457')
            ->and($references[1]['provider_remote_id'])->toBe('459');
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

    test('IMAP recursively rejects an oversized inline binary part before fetching its content', function () {
        config([
            'attachments.max_file_bytes' => 10,
            'attachments.max_message_bytes' => 20,
        ]);
        ImapFunctionState::$structure = (object) [
            'type' => 1,
            'parts' => [
                (object) [
                    'type' => 0,
                    'subtype' => 'PLAIN',
                    'encoding' => 0,
                    'bytes' => strlen('Message body'),
                ],
                (object) [
                    'type' => 1,
                    'subtype' => 'RELATED',
                    'parts' => [
                        (object) [
                            'type' => 5,
                            'subtype' => 'PNG',
                            'encoding' => 3,
                            'bytes' => 1000,
                            'ifdisposition' => 1,
                            'disposition' => 'inline',
                        ],
                    ],
                ],
            ],
        ];
        ImapFunctionState::$bodyBySection = [
            '1' => 'Message body',
            '2.1' => base64_encode(str_repeat('x', 1000)),
        ];
        $connector = imapConnectorForTest();
        $message = $connector->fetchEmail(iterator_to_array($connector->fetchNewEmailReferences())[0]);

        expect($message['attachments'])->toBe([])
            ->and($message['attachment_rejection']['reason_code'])->toBe('file_too_large')
            ->and($message['attachment_rejection']['reported_count'])->toBe(1)
            ->and($message['body_text'])->toBe('Message body')
            ->and(ImapFunctionState::$fetchBodySections)->toBe(['1']);
    });

    test('IMAP recursively admits nested bodies and a named nested attachment', function () {
        ImapFunctionState::$structure = (object) [
            'type' => 1,
            'parts' => [
                (object) [
                    'type' => 1,
                    'subtype' => 'ALTERNATIVE',
                    'parts' => [
                        (object) [
                            'type' => 0,
                            'subtype' => 'PLAIN',
                            'encoding' => 0,
                            'bytes' => strlen('Nested plain body'),
                        ],
                        (object) [
                            'type' => 0,
                            'subtype' => 'HTML',
                            'encoding' => 0,
                            'bytes' => strlen('<p>Nested HTML body</p>'),
                        ],
                    ],
                ],
                (object) [
                    'type' => 3,
                    'subtype' => 'PDF',
                    'encoding' => 0,
                    'bytes' => strlen('attachment-content'),
                    'parameters' => [(object) ['attribute' => 'name', 'value' => 'nested.pdf']],
                ],
            ],
        ];
        ImapFunctionState::$bodyBySection = [
            '1.1' => 'Nested plain body',
            '1.2' => '<p>Nested HTML body</p>',
            '2' => 'attachment-content',
        ];
        $connector = imapConnectorForTest();
        $message = $connector->fetchEmail(iterator_to_array($connector->fetchNewEmailReferences())[0]);

        expect($message)->not->toHaveKey('attachment_rejection')
            ->and($message['body_text'])->toBe('Nested plain body')
            ->and($message['body_html'])->toBe('<p>Nested HTML body</p>')
            ->and($message['attachments'])->toHaveCount(1)
            ->and($message['attachments'][0]['filename'])->toBe('nested.pdf')
            ->and(ImapFunctionState::$fetchBodySections)->toBe(['2', '1.1', '1.2']);
    });

    test('IMAP omits a body over the declared limit without fetching it', function () {
        config(['attachments.max_body_bytes' => 10]);
        ImapFunctionState::$structure = (object) [
            'type' => 0,
            'subtype' => 'PLAIN',
            'encoding' => 0,
            'bytes' => 1000,
        ];
        $connector = imapConnectorForTest();
        $message = $connector->fetchEmail(iterator_to_array($connector->fetchNewEmailReferences())[0]);

        expect($message['body_text'])->toContain('omitted')
            ->and($message['attachments'])->toBe([])
            ->and(ImapFunctionState::$fetchBodySections)->toBe([]);
    });

    test('IMAP rechecks aggregate actual bytes when structure metadata underreports them', function () {
        config(['attachments.max_body_bytes' => 10]);
        ImapFunctionState::$structure = (object) [
            'type' => 1,
            'parts' => [
                (object) ['type' => 0, 'subtype' => 'PLAIN', 'encoding' => 0, 'bytes' => 1],
                (object) ['type' => 0, 'subtype' => 'HTML', 'encoding' => 0, 'bytes' => 1],
            ],
        ];
        ImapFunctionState::$bodyBySection = [
            '1' => '123456',
            '2' => '12345',
        ];
        $message = imapConnectorForTest()->fetchEmail([
            'provider_message_id' => 'imap:INBOX:123:456',
            'provider_remote_id' => '456',
            'uid_validity' => 123,
        ]);

        expect($message['body_text'])->toContain('omitted')
            ->and($message['body_html'])->toBeNull()
            ->and(ImapFunctionState::$fetchBodySections)->toBe(['1', '2']);
    });

    test('IMAP admits a transfer-encoded body exactly at the configured limit', function () {
        config(['attachments.max_body_bytes' => 10]);
        ImapFunctionState::$structure = (object) [
            'type' => 0,
            'subtype' => 'PLAIN',
            'encoding' => 3,
            'bytes' => strlen(base64_encode('1234567890')),
        ];
        ImapFunctionState::$bodyBySection = ['1' => base64_encode('1234567890')];
        $message = imapConnectorForTest()->fetchEmail([
            'provider_message_id' => 'imap:INBOX:123:456',
            'provider_remote_id' => '456',
            'uid_validity' => 123,
        ]);

        expect($message['body_text'])->toBe('1234567890')
            ->and($message['body_html'])->toBeNull();
    });

    test('IMAP terminally omits a MIME tree over the traversal limits', function () {
        config([
            'attachments.max_mime_depth' => 1,
            'attachments.max_mime_parts' => 10,
        ]);
        ImapFunctionState::$structure = (object) [
            'type' => 1,
            'parts' => [(object) [
                'type' => 1,
                'parts' => [(object) [
                    'type' => 0,
                    'subtype' => 'PLAIN',
                    'encoding' => 0,
                    'bytes' => 6,
                ]],
            ]],
        ];
        $message = imapConnectorForTest()->fetchEmail([
            'provider_message_id' => 'imap:INBOX:123:456',
            'provider_remote_id' => '456',
            'uid_validity' => 123,
        ]);

        expect($message['body_text'])->toContain('omitted')
            ->and($message['attachments'])->toBe([])
            ->and($message['attachment_rejection']['reason_code'])->toBe('invalid_metadata')
            ->and(ImapFunctionState::$fetchBodySections)->toBe([]);
    });

    test('IMAP rejects a wide MIME root before scheduling all children', function () {
        config(['attachments.max_mime_parts' => 2]);
        ImapFunctionState::$structure = (object) [
            'type' => 1,
            'parts' => array_fill(0, 100, (object) [
                'type' => 0,
                'subtype' => 'PLAIN',
                'encoding' => 0,
                'bytes' => 1,
            ]),
        ];
        $message = imapConnectorForTest()->fetchEmail([
            'provider_message_id' => 'imap:INBOX:123:456',
            'provider_remote_id' => '456',
            'uid_validity' => 123,
        ]);

        expect($message['body_text'])->toContain('omitted')
            ->and($message['attachment_rejection']['reason_code'])->toBe('invalid_metadata')
            ->and(ImapFunctionState::$fetchBodySections)->toBe([]);
    });

    test('IMAP treats attachment content fetch failure as retryable instead of an empty attachment', function () {
        ImapFunctionState::$bodyBySection = ['2' => false];
        $connector = imapConnectorForTest();

        expect(fn () => $connector->fetchEmail(iterator_to_array($connector->fetchNewEmailReferences())[0]))
            ->toThrow(RuntimeException::class, 'IMAP message part fetch failed.');
        expect(ImapFunctionState::$fetchBodySections)->toBe(['2'])
            ->and(ImapFunctionState::$seenWrites)->toBe(0);
    });

    test('IMAP treats body content fetch failure as retryable instead of acknowledging an empty body', function () {
        ImapFunctionState::$attachmentCount = 0;
        ImapFunctionState::$bodyBySection = ['1' => false];
        $connector = imapConnectorForTest();

        expect(fn () => $connector->fetchEmail(iterator_to_array($connector->fetchNewEmailReferences())[0]))
            ->toThrow(RuntimeException::class, 'IMAP message part fetch failed.');
        expect(ImapFunctionState::$fetchBodySections)->toBe(['1'])
            ->and(ImapFunctionState::$seenWrites)->toBe(0);
    });

    function imapConnectorForTest(): ImapConnector
    {
        $connector = new ImapConnector;
        $reflection = new ReflectionClass($connector);

        foreach ([
            'connection' => new stdClass,
            'mailbox' => Mailbox::factory()->create(),
            'uidValidity' => 123,
        ] as $propertyName => $value) {
            $reflection->getProperty($propertyName)->setValue($connector, $value);
        }

        return $connector;
    }
}
