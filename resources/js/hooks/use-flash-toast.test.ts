import { describe, expect, it } from 'vite-plus/test';
import { refusal } from '@/hooks/use-flash-toast';

const plain = { 'content-type': 'text/plain; charset=UTF-8' };

describe('refusal', () => {
    it("shows a limit's own line, which says how long to wait", () => {
        expect(
            refusal({
                status: 429,
                headers: plain,
                data: 'Too many tries. Try again in about an hour.',
            }),
        ).toBe('Too many tries. Try again in about an hour.');
    });

    it('falls back to a general line for a 429 without one', () => {
        expect(
            refusal({ status: 429, headers: {}, data: '<html></html>' }),
        ).toBe('Too many tries. Wait a minute and try again.');
    });

    it('shows a plain-text refusal, and leaves anything else to the dialog', () => {
        expect(
            refusal({
                status: 403,
                headers: plain,
                data: 'Email changes are off in this demo.',
            }),
        ).toBe('Email changes are off in this demo.');
        expect(
            refusal({
                status: 500,
                headers: { 'content-type': 'text/html' },
                data: '<html></html>',
            }),
        ).toBeNull();
    });
});
