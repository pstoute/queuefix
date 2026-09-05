import DOMPurify from 'dompurify';

interface SafeMessageBodyProps {
    bodyHtml?: string | null;
    bodyText?: string | null;
    className?: string;
    plainTextClassName?: string;
}

export function SafeMessageBody({
    bodyHtml,
    bodyText,
    className,
    plainTextClassName = className,
}: SafeMessageBodyProps) {
    if (!bodyHtml) {
        return <div className={plainTextClassName}>{bodyText}</div>;
    }

    const sanitizedHtml = DOMPurify.sanitize(bodyHtml, {
        USE_PROFILES: { html: true },
    });

    return <div className={className} dangerouslySetInnerHTML={{ __html: sanitizedHtml }} />;
}
