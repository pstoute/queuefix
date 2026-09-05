<?php

namespace App\Models;

use App\Enums\AttachmentScanStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property AttachmentScanStatus $scan_status
 * @property Message $message
 * @property string|null $path
 */
class Attachment extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'filename',
        'path',
        'mime_type',
        'claimed_mime_type',
        'size',
        'sha256',
        'scan_status',
        'rejection_reason',
    ];

    /** @var list<string> */
    protected $hidden = [
        'path',
        'claimed_mime_type',
        'sha256',
        'rejection_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'scan_status' => AttachmentScanStatus::class,
        ];
    }

    /**
     * Get the message that owns the attachment.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
