<?php

namespace Database\Factories;

use App\Enums\AttachmentScanStatus;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Attachment> */
class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        $contents = fake()->sentence();

        return [
            'message_id' => Message::factory(),
            'filename' => 'document.txt',
            'path' => 'attachments/tickets/'.fake()->uuid().'/'.fake()->uuid(),
            'mime_type' => 'text/plain',
            'claimed_mime_type' => 'text/plain',
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'scan_status' => AttachmentScanStatus::Clean,
            'rejection_reason' => null,
        ];
    }
}
