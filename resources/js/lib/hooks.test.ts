import { afterEach, describe, expect, it, vi } from 'vitest';

import { formatRelativeTime } from './hooks';

const now = new Date('2026-08-05T15:00:00.000Z');

function timestampOffsetBy(seconds: number): string {
    return new Date(now.getTime() + seconds * 1000).toISOString();
}

describe('formatRelativeTime', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('clamps future timestamps to zero elapsed seconds', () => {
        vi.useFakeTimers();
        vi.setSystemTime(now);

        expect(formatRelativeTime(timestampOffsetBy(30))).toBe('0s ago');
    });

    it('formats representative past-time boundaries using existing conventions', () => {
        vi.useFakeTimers();
        vi.setSystemTime(now);

        expect(formatRelativeTime(timestampOffsetBy(-59))).toBe('59s ago');
        expect(formatRelativeTime(timestampOffsetBy(-60))).toBe('1m ago');
        expect(formatRelativeTime(timestampOffsetBy(-3_599))).toBe('59m ago');
        expect(formatRelativeTime(timestampOffsetBy(-3_600))).toBe('1h ago');
        expect(formatRelativeTime(timestampOffsetBy(-86_400))).toBe('1d ago');
        expect(formatRelativeTime(timestampOffsetBy(-2_592_000))).toBe('1mo ago');
        expect(formatRelativeTime(timestampOffsetBy(-31_104_000))).toBe('1y ago');
    });
});
