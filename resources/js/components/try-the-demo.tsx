import { Form } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { store as startDemo } from '@/routes/demo';

/**
 * "Try the demo": makes the visitor a sandbox of their own and opens its Acme board. On the front page and, on the
 * hosted demo, the sign-in page.
 */
export function TryTheDemo({ wide = false }: { wide?: boolean }) {
    return (
        <Form {...startDemo.form()} className="space-y-4">
            {({ processing }) => (
                <>
                    <Button
                        type="submit"
                        size="lg"
                        disabled={processing}
                        className={wide ? 'w-full' : undefined}
                        data-test="try-the-demo"
                    >
                        {processing && <Spinner />}
                        Try the demo
                    </Button>
                    <p
                        className={cn(
                            'max-w-md text-sm text-muted-foreground',
                            wide && 'text-center',
                        )}
                    >
                        You get a sandbox of your own: a sample board, two
                        teammates on it, and a third person in another team.
                        Nobody else sees it, and it is deleted after 24 hours.
                    </p>
                </>
            )}
        </Form>
    );
}
