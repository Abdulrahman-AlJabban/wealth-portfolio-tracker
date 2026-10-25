import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';

// Temporary: prints the props so their shape can be checked. Replaced by the real page in a later task.
export default function Show(props: PageProps) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Portfolios
                </h2>
            }
        >
            <Head title="Portfolios" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <pre className="overflow-x-auto bg-white p-6 text-xs shadow-sm sm:rounded-lg">
                        {JSON.stringify(props, null, 2)}
                    </pre>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
