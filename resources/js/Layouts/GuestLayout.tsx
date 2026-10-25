import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';
import { PropsWithChildren, ReactNode } from 'react';

/**
 * Layout for pages without a signed-in user (login, register, password reset) and for error pages.
 * It uses the same top bar, background and card style as AuthenticatedLayout, so signing in does not
 * feel like moving to a different app.
 */
export default function Guest({
    title,
    description,
    footer,
    children,
}: PropsWithChildren<{ title?: string; description?: string; footer?: ReactNode }>) {
    return (
        <div className="min-h-screen bg-gray-100">
            <nav className="border-b border-gray-100 bg-white">
                <div className="mx-auto flex h-16 max-w-7xl items-center px-4 sm:px-6 lg:px-8">
                    <Link
                        href="/"
                        className="flex items-center gap-3 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                    >
                        <ApplicationLogo className="block h-9 w-auto fill-current text-gray-800" />
                        <span className="text-sm font-medium text-gray-800">
                            Wealth Portfolio Tracker
                        </span>
                    </Link>
                </div>
            </nav>

            <main className="px-4 py-8 sm:py-16">
                <div className="mx-auto w-full max-w-md">
                    <div className="rounded-lg bg-white p-5 shadow-sm sm:p-6">
                        {title && (
                            <div className="mb-6">
                                <h1 className="text-xl font-medium leading-tight text-gray-800">
                                    {title}
                                </h1>
                                {description && (
                                    <p className="mt-1 text-sm text-gray-500">
                                        {description}
                                    </p>
                                )}
                            </div>
                        )}

                        {children}
                    </div>

                    {footer && (
                        <div className="mt-6 text-center text-sm text-gray-600">
                            {footer}
                        </div>
                    )}
                </div>
            </main>
        </div>
    );
}
