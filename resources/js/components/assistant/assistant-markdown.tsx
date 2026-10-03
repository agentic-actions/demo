import type { ComponentProps } from 'react';
import {
    defaultRehypePlugins,
    Streamdown,
    type Components,
    type ControlsConfig,
    type ExtraProps,
} from 'streamdown';

/**
 * The HTML pass: GitHub's sanitize schema, which drops any href or src that is not http(s) or a few mail protocols.
 * Streamdown's own default also runs rehype-raw, which would turn HTML the model wrote into elements. Without it,
 * Streamdown shows that HTML as text.
 */
const rehypePlugins = [defaultRehypePlugins.sanitize];

/** Copy on code blocks only: no downloads, and no table menus in a 384px panel. */
const controls: ControlsConfig = {
    code: { copy: true, download: false },
    table: false,
    image: false,
    mermaid: false,
};

/**
 * Sizes for a 384px panel. Streamdown's elements carry utility classes, which these descendant variants outrank. With
 * dir="auto" each block sits in a `display: contents` wrapper that carries its direction, and a margin on those
 * wrappers does nothing, so the blocks are flex items spaced by the gap, and their own vertical margins are dropped.
 */
const layout = [
    'flex flex-col gap-2 space-y-0 break-words [&_[data-streamdown]]:my-0',
    '[&_h1]:text-base [&_h2]:text-base [&_h3]:text-sm [&_h4]:text-sm',
    '[&_ol]:list-outside [&_ol]:ps-5 [&_ul]:list-outside [&_ul]:ps-5 [&_li]:py-0.5',
    '[&_pre]:text-xs [&_td]:px-2 [&_td]:py-1 [&_th]:px-2 [&_th]:py-1',
].join(' ');

/** Module scope, so the memoized blocks of a streaming reply keep the same components. */
const components: Components = {
    a: WebLink,
    img: ImageAltText,
    strong: Strong,
};

/**
 * An assistant reply as Markdown. The words come from a model, and a model repeats whatever it read, so they are
 * untrusted: no image ever loads (an image shows its alt text), a link opens only to an http(s) URL, in a new tab,
 * and HTML shows as text. While a reply streams, Streamdown closes unfinished syntax, so a half-written `**` or link
 * never shows. `dir="auto"` gives each block the direction of its own words, and code always reads left to right.
 */
export default function AssistantMarkdown({
    text,
    streaming,
}: {
    text: string;
    streaming: boolean;
}) {
    return (
        <Streamdown
            dir="auto"
            isAnimating={streaming}
            rehypePlugins={rehypePlugins}
            components={components}
            controls={controls}
            lineNumbers={false}
            className={layout}
        >
            {text}
        </Streamdown>
    );
}

/** An absolute http(s) URL, or null for anything else: javascript:, data:, a relative path, Streamdown's placeholder. */
function webUrl(href: unknown): string | null {
    if (typeof href !== 'string') {
        return null;
    }

    try {
        const url = new URL(href);

        return url.protocol === 'http:' || url.protocol === 'https:'
            ? url.href
            : null;
    } catch {
        return null;
    }
}

function WebLink({ href, children }: ComponentProps<'a'> & ExtraProps) {
    const url = webUrl(href);

    if (url === null) {
        return <span>{children}</span>;
    }

    return (
        <a
            href={url}
            target="_blank"
            rel="noopener noreferrer nofollow"
            className="font-medium wrap-anywhere underline underline-offset-2"
        >
            {children}
        </a>
    );
}

function ImageAltText({ alt }: ComponentProps<'img'> & ExtraProps) {
    return alt ? <span>{alt}</span> : null;
}

/** Streamdown draws bold as a span. A <strong> keeps the emphasis for a screen reader. */
function Strong({ children }: ComponentProps<'strong'> & ExtraProps) {
    return <strong className="font-semibold">{children}</strong>;
}
