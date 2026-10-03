import type { SVGAttributes } from 'react';

/**
 * The demo's mark: three board columns. Filled shapes, so the callers'
 * fill-current classes colour it.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <rect x="3" y="3" width="5" height="18" rx="1.5" />
            <rect x="9.5" y="3" width="5" height="12" rx="1.5" />
            <rect x="16" y="3" width="5" height="7" rx="1.5" />
        </svg>
    );
}
