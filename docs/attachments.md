# Attachment security

QueueFix sends every inbound-email and web-upload attachment through the same
`AttachmentService` before it writes an object or database row.

## Limits and allowed formats

The default limits are 10 files per message, 10 MB per file, 25 MB for the
combined message, 2 MB for an IMAP message body, 5 GB per mailbox, and 25 GB for
the installation. They can be changed with the `ATTACHMENT_*` and
`INBOUND_EMAIL_MAX_BODY_BYTES` environment variables documented in
`.env.example`.

Mailbox connectors queue only stable provider message references. The
processing job hydrates one message at a time, checks provider-owned count and
size metadata before requesting attachment content, and rechecks actual bytes
before storage. Graph attachment bodies are streamed only up to the configured
limit. IMAP walks nested MIME leaves and treats named text parts, inline binary
parts, and non-text leaves as attachments; bodies with missing or excessive
size metadata are omitted without fetching. Storage admission is serialized so
concurrent workers cannot race past the cumulative quotas. Messages rejected by
attachment policy retain their body and a rejected metadata record, then
complete provider acknowledgement instead of retrying forever.

QueueFix accepts PDF, plain text, CSV, PNG, JPEG, GIF, WebP, and macro-free
Office Open XML documents (`.docx`, `.xlsx`, and `.pptx`). It rejects generic
archives, macro-enabled Office formats, executables, SVG, HTML, password-
protected or active-content PDFs, MIME/extension mismatches, unsafe paths, and
Office containers with embedded or active content. Office containers also have
entry-count, expanded-size, and compression-ratio limits.

Duplicate files are retained as separate attachment records and isolated
storage objects. Their SHA-256 digests are identical, which makes duplicates
auditable and permits a future retention-safe deduplication process without
changing conversation semantics.

## Scanning and delivery

`ATTACHMENT_SCANNING_REQUIRED` defaults to `true`. The bundled scanner reports
`pending`, so a deployment must bind `AttachmentScanner` to its malware scanner
implementation before files become downloadable. A synchronous scanner may
return `clean`, `pending`, or `rejected`:

- `clean` files are available through authorization-checked download routes.
- `pending` files remain private and return HTTP 423 until scanning completes.
- `rejected` files retain safe metadata and a reason code, but no file object.

The application never exposes storage paths or public-disk URLs. Downloads use
`Content-Disposition: attachment`, `application/octet-stream`, `nosniff`, a
sandboxing content security policy, and private/no-store caching.
