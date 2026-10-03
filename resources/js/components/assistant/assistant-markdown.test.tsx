import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vite-plus/test';
import AssistantMarkdown from '@/components/assistant/assistant-markdown';

function render(text: string, streaming = false): string {
    return renderToStaticMarkup(
        <AssistantMarkdown text={text} streaming={streaming} />,
    );
}

describe('AssistantMarkdown', () => {
    it('renders a list and bold words', () => {
        const html = render(
            '**2 tasks** are overdue:\n\n- Fix the pricing links (Marcus Reed, due 23 Sep)\n- Crash on login (unassigned, due 21 Sep)',
        );

        expect(html).toContain('<strong');
        expect(html).toContain('2 tasks</strong>');
        expect(html).toMatch(/<ul[^>]*>/);
        expect(html.match(/<li[^>]*>/g)).toHaveLength(2);
        expect(html).not.toContain('**');
    });

    it('loads no image, and shows its alt text instead', () => {
        const html = render('![tracking pixel](https://evil.example/p.png)');

        expect(html).not.toMatch(/<img/i);
        expect(html).not.toContain('evil.example');
        expect(html).toContain('tracking pixel');
    });

    it('opens http(s) links in a new tab without the opener or a referrer', () => {
        const html = render('Read [the guide](https://example.com/guide).');

        expect(html).toContain('href="https://example.com/guide"');
        expect(html).toContain('target="_blank"');
        expect(html).toContain('rel="noopener noreferrer nofollow"');
    });

    it.each([
        ['javascript:', '[click me](javascript:alert(1))'],
        [
            'an entity-encoded javascript:',
            '[click me](jav&#x61;script:alert(1))',
        ],
        ['data:', '[click me](data:text/html;base64,PHNjcmlwdD4=)'],
        ['vbscript:', '[click me](vbscript:msgbox(1))'],
        ['a relative path', '[click me](/acme/board)'],
        [
            'a reference link to javascript:',
            '[click me][x]\n\n[x]: javascript:alert(1)',
        ],
    ])('shows a link to %s as text', (_, markdown) => {
        const html = render(markdown);

        expect(html).not.toMatch(/<a[\s>]/);
        expect(html).not.toMatch(/href=/);
        expect(html).toContain('click me');
    });

    it('never turns HTML the model wrote into elements', () => {
        const html = render(
            'Hi <script>window.pwned = 1</script>\n\n<img src=x onerror="window.pwned = 1">\n\n<a href="javascript:alert(1)">x</a> <iframe src="https://evil.example"></iframe> <b onclick="alert(1)">b</b>',
        );

        // Every "<" left is a tag Markdown made; the model's own tags show as text, escaped.
        expect(html).not.toMatch(/<(script|img|iframe|a|b)[\s>]/i);
        expect(html).not.toMatch(/<[^>]*\son[a-z]+=/i);
        expect(html).toContain('&lt;script&gt;');
        expect(html).toContain('&lt;img src=x onerror=');
    });

    it('never shows half-written syntax while a reply streams', () => {
        expect(render('Two tasks are **overd', true)).toContain('<strong');
        expect(render('Two tasks are **overd', true)).not.toContain('**');

        const link = render('See [the guide](https://exam', true);

        expect(link).not.toContain('](');
        expect(link).not.toMatch(/href=/);
        expect(link).toContain('the guide');
    });

    it('gives Arabic words right-to-left and English left-to-right', () => {
        expect(render('المهام **المتأخرة**: مهمتان')).toContain('dir="rtl"');
        expect(render('Two tasks are **overdue**')).toContain('dir="ltr"');
    });
});
