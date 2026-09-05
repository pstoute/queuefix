import DOMPurify from 'dompurify';

const INERT_MESSAGE_TAGS = [
    'a',
    'abbr',
    'b',
    'blockquote',
    'br',
    'code',
    'dd',
    'del',
    'div',
    'dl',
    'dt',
    'em',
    'h1',
    'h2',
    'h3',
    'h4',
    'h5',
    'h6',
    'hr',
    'i',
    'img',
    'li',
    'ol',
    'p',
    'pre',
    's',
    'small',
    'span',
    'strong',
    'sub',
    'sup',
    'table',
    'tbody',
    'td',
    'tfoot',
    'th',
    'thead',
    'tr',
    'u',
    'ul',
];

const INERT_MESSAGE_ATTRIBUTES = [
    'alt',
    'colspan',
    'dir',
    'height',
    'href',
    'lang',
    'rowspan',
    'src',
    'title',
    'width',
];

const SAFE_CID_IMAGE = /^cid:[^\s<>"']+$/i;
const SAFE_DATA_IMAGE = /^data:image\/(?:gif|jpe?g|png|webp);base64,/i;

function sanitizeMessageHtml(bodyHtml: string): string {
    const sanitizedHtml = DOMPurify.sanitize(bodyHtml, {
        ALLOWED_ATTR: INERT_MESSAGE_ATTRIBUTES,
        ALLOWED_TAGS: INERT_MESSAGE_TAGS,
        ALLOW_DATA_ATTR: false,
    });

    const template = document.createElement('template');
    template.innerHTML = sanitizedHtml;

    template.content.querySelectorAll('img').forEach((image) => {
        const source = image.getAttribute('src');

        if (source && !SAFE_CID_IMAGE.test(source) && !SAFE_DATA_IMAGE.test(source)) {
            image.removeAttribute('src');
        }
    });

    template.content.querySelectorAll('a[href]').forEach((link) => {
        const href = link.getAttribute('href');

        link.setAttribute('rel', 'nofollow noopener noreferrer');
        if (href && /^(?:https?:)?\/\//i.test(href)) {
            link.setAttribute('target', '_blank');
        }
    });

    return template.innerHTML;
}

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

    const sanitizedHtml = sanitizeMessageHtml(bodyHtml);

    return <div className={className} dangerouslySetInnerHTML={{ __html: sanitizedHtml }} />;
}
