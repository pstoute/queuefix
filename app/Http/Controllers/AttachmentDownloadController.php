<?php

namespace App\Http\Controllers;

use App\Enums\AttachmentScanStatus;
use App\Enums\MessageType;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentDownloadController extends Controller
{
    public function agent(Attachment $attachment): StreamedResponse
    {
        $attachment->loadMissing('message.ticket');
        /** @var Message $message */
        $message = $attachment->message;
        /** @var Ticket $ticket */
        $ticket = $message->ticket;
        Gate::authorize('view', $ticket);

        return $this->download($attachment);
    }

    public function customer(Request $request, Attachment $attachment): StreamedResponse
    {
        $attachment->loadMissing('message.ticket');
        /** @var Message $message */
        $message = $attachment->message;
        /** @var Ticket $ticket */
        $ticket = $message->ticket;
        $customer = $request->user('customer');

        abort_unless($customer && $ticket->customer_id === $customer->id, 403);
        abort_unless($message->type === MessageType::Reply->value, 403);

        return $this->download($attachment);
    }

    private function download(Attachment $attachment): StreamedResponse
    {
        /** @var AttachmentScanStatus $scanStatus */
        $scanStatus = $attachment->scan_status;
        abort_unless($scanStatus === AttachmentScanStatus::Clean, 423, 'Attachment security scanning is not complete.');
        abort_unless($attachment->path !== null, 404);

        $disk = Storage::disk((string) config('attachments.disk'));
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->filename, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
