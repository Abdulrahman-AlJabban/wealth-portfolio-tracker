import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link } from '@inertiajs/react';

const MESSAGES: Record<number, { title: string; body: string }> = {
    403: {
        title: 'You do not have access to this',
        body: 'Your role in this portfolio does not allow that action, or you are not a member.',
    },
    404: {
        title: 'Not found',
        body: 'This page does not exist, or the portfolio was deleted.',
    },
};

// Rendered by bootstrap/app.php for 403 and 404 responses. It uses the guest layout because
// shared props such as auth may be missing when an exception is rendered.
export default function Error({ status }: { status: number }) {
    const message = MESSAGES[status] ?? { title: 'Something went wrong', body: 'Please try again.' };

    return (
        <GuestLayout>
            <Head title={message.title} />

            <div role="alert">
                <p className="text-sm text-gray-500">Error {status}</p>
                <h1 className="mt-1 text-lg font-medium text-gray-900">{message.title}</h1>
                <p className="mt-2 text-sm text-gray-600">{message.body}</p>
            </div>

            <Link
                href="/"
                className="mt-6 inline-block rounded text-sm font-medium text-gray-700 underline underline-offset-2 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
            >
                Back to your portfolios
            </Link>
        </GuestLayout>
    );
}
