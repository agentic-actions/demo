import { Skeleton } from '@/components/ui/skeleton';

/** The chat's placeholder while its code or the conversation loads. */
export default function AssistantSkeleton() {
    return (
        <div className="flex flex-1 flex-col gap-3 p-4" aria-busy>
            <Skeleton className="h-4 w-2/3" />
            <Skeleton className="ms-auto h-8 w-1/2" />
            <Skeleton className="h-4 w-3/4" />
        </div>
    );
}
