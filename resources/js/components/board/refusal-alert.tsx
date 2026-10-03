import { CircleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

/**
 * What the server said when it stopped a call that was not a field error: authorize() said no (403), the task is in
 * another team (404), or the action refused without a field (409).
 */
export default function RefusalAlert({ message }: { message: string | null }) {
    if (message === null) {
        return null;
    }

    return (
        <Alert variant="destructive">
            <CircleAlert />
            <AlertTitle>Not done</AlertTitle>
            <AlertDescription>{message}</AlertDescription>
        </Alert>
    );
}
