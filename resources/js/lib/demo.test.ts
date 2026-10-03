import { describe, expect, it } from 'vite-plus/test';
import { timeLeft } from '@/lib/demo';

describe('timeLeft', () => {
    const now = Date.parse('2026-10-03T12:00:00Z');

    it('counts hours and minutes, rounded down', () => {
        expect(timeLeft('2026-10-04T11:59:59Z', now)).toBe('23 h 59 m');
        expect(timeLeft('2026-10-03T13:00:00Z', now)).toBe('1 h 0 m');
    });

    it('drops the hours under an hour', () => {
        expect(timeLeft('2026-10-03T12:42:30Z', now)).toBe('42 m');
        expect(timeLeft('2026-10-03T12:00:30Z', now)).toBe('under a minute');
    });

    it('gives null once the time has passed, or for a date it cannot read', () => {
        expect(timeLeft('2026-10-03T12:00:00Z', now)).toBeNull();
        expect(timeLeft('2026-10-03T11:00:00Z', now)).toBeNull();
        expect(timeLeft('not a date', now)).toBeNull();
    });
});
