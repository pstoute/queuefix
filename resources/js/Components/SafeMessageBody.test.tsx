import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { SafeMessageBody } from './SafeMessageBody';

describe('SafeMessageBody', () => {
    it('removes executable HTML while preserving safe content', () => {
        const { container } = render(
            <SafeMessageBody
                bodyHtml={`
                    <p>Hello <strong>support</strong></p>
                    <img src="x" onerror="window.__queuefixXss = true">
                    <svg><g onload="window.__queuefixXss = true"></g></svg>
                    <a href="javascript:window.__queuefixXss = true">unsafe link</a>
                    <script>window.__queuefixXss = true</script>
                `}
            />,
        );

        expect(container.querySelector('strong')).toHaveTextContent('support');
        expect(container.querySelector('script')).not.toBeInTheDocument();
        expect(container.querySelector('svg')).not.toBeInTheDocument();
        expect(container.querySelector('img')).not.toHaveAttribute('onerror');
        expect(container.querySelector('a')).not.toHaveAttribute('href');
    });

    it('renders plain-text markup literally', () => {
        const payload = '<img src="x" onerror="window.__queuefixXss = true">';
        const { container } = render(<SafeMessageBody bodyText={payload} />);

        expect(container).toHaveTextContent(payload);
        expect(container.querySelector('img')).not.toBeInTheDocument();
    });

    it('preserves representative email formatting and safe resource URLs', () => {
        const { container } = render(
            <SafeMessageBody
                bodyHtml={`
                    <p style="color: red"><em>Formatted message</em></p>
                    <table><tbody><tr><td>Cell</td></tr></tbody></table>
                    <a href="https://example.com/help">Help</a>
                    <img src="cid:logo@example.com" alt="Company logo">
                `}
            />,
        );

        expect(container.querySelector('p')).toHaveStyle({ color: 'rgb(255, 0, 0)' });
        expect(container.querySelector('em')).toHaveTextContent('Formatted message');
        expect(container.querySelector('td')).toHaveTextContent('Cell');
        expect(container.querySelector('a')).toHaveAttribute('href', 'https://example.com/help');
        expect(container.querySelector('img')).toHaveAttribute('src', 'cid:logo@example.com');
    });
});
