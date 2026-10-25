import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import RoleBadge from '@/Components/RoleBadge';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { IndexProps } from '@/types/portfolio';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

const CURRENCIES = ['USD', 'EUR', 'GBP', 'SYP'];

export default function Index({ portfolios }: IndexProps) {
    const form = useForm({ name: '', base_currency: 'USD' });

    function submit(event: FormEvent) {
        event.preventDefault();
        // On success the server redirects to the new portfolio. On error Inertia keeps the input.
        form.post(route('portfolios.store'));
    }

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Portfolios
                </h2>
            }
        >
            <Head title="Portfolios" />

            <div className="py-8 sm:py-12">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <section aria-labelledby="your-portfolios">
                        <h3 id="your-portfolios" className="sr-only">
                            Your portfolios
                        </h3>

                        {portfolios.length === 0 ? (
                            <p className="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-600">
                                You do not have a portfolio yet. Create your
                                first one below.
                            </p>
                        ) : (
                            <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {portfolios.map((portfolio) => (
                                    <li key={portfolio.id}>
                                        <Link
                                            href={route('portfolios.show', portfolio.id)}
                                            className="block rounded-lg border border-gray-200 bg-white p-5 shadow-sm transition hover:border-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <span className="font-semibold text-gray-900">
                                                    {portfolio.name}
                                                </span>
                                                <RoleBadge role={portfolio.role} />
                                            </div>
                                            <span className="mt-2 block text-sm text-gray-500">
                                                {portfolio.base_currency}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section
                        aria-labelledby="create-portfolio"
                        className="rounded-lg bg-white p-5 shadow-sm sm:p-6"
                    >
                        <h3
                            id="create-portfolio"
                            className="text-base font-semibold text-gray-900"
                        >
                            Create a portfolio
                        </h3>

                        <form
                            onSubmit={submit}
                            className="mt-4 flex flex-col gap-4 sm:flex-row sm:items-start"
                        >
                            <div className="sm:flex-1">
                                <InputLabel htmlFor="name" value="Name" />
                                <TextInput
                                    id="name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    maxLength={100}
                                    required
                                    aria-invalid={form.errors.name ? true : undefined}
                                    aria-describedby={form.errors.name ? 'name-error' : undefined}
                                    className="mt-1 block w-full"
                                />
                                <InputError id="name-error" message={form.errors.name} className="mt-1" />
                            </div>

                            <div>
                                <InputLabel htmlFor="base_currency" value="Currency" />
                                <select
                                    id="base_currency"
                                    value={form.data.base_currency}
                                    onChange={(event) =>
                                        form.setData('base_currency', event.target.value)
                                    }
                                    aria-invalid={form.errors.base_currency ? true : undefined}
                                    aria-describedby="base_currency-hint base_currency-error"
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:w-32"
                                >
                                    {CURRENCIES.map((currency) => (
                                        <option key={currency} value={currency}>
                                            {currency}
                                        </option>
                                    ))}
                                </select>
                                <InputError id="base_currency-error" message={form.errors.base_currency} className="mt-1" />
                            </div>

                            <div className="sm:pt-6">
                                <PrimaryButton type="submit" disabled={form.processing}>
                                    {form.processing ? 'Creating…' : 'Create'}
                                </PrimaryButton>
                            </div>
                        </form>

                        <p id="base_currency-hint" className="mt-3 text-xs text-gray-500">
                            Market assets (BTC, ETH, AAPL) are priced in USD.
                            A portfolio in another currency can only hold
                            private assets.
                        </p>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
