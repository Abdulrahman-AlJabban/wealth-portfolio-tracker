import HoldingsTable, { ProfitText } from '@/Components/HoldingsTable';
import RoleBadge from '@/Components/RoleBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatAmount } from '@/lib/format';
import { ShowProps } from '@/types/portfolio';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

const TABS = [
    { id: 'holdings', label: 'Holdings' },
    { id: 'transactions', label: 'Transactions' },
    { id: 'members', label: 'Members' },
] as const;

type TabId = (typeof TABS)[number]['id'];

function tabFromHash(): TabId {
    const hash = window.location.hash.slice(1);

    return TABS.find((tab) => tab.id === hash)?.id ?? 'holdings';
}

export default function Show({ portfolio, role, summary }: ShowProps) {
    const [tab, setTab] = useState<TabId>(tabFromHash);

    function selectTab(id: TabId) {
        setTab(id);
        // Keep Inertia's own history state; only the hash changes, so a refresh reopens this tab.
        window.history.replaceState(window.history.state, '', `#${id}`);
    }

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <h2 className="text-xl font-semibold leading-tight text-gray-800">
                                {portfolio.name}
                            </h2>
                            <RoleBadge role={role} />
                        </div>
                        <p className="mt-1 text-sm text-gray-500">
                            Amounts in {portfolio.base_currency}
                        </p>
                    </div>

                    <dl className="grid grid-cols-3 gap-4 tabular-nums sm:gap-8 sm:text-right">
                        <div>
                            <dt className="text-xs uppercase tracking-wide text-gray-500">Total value</dt>
                            <dd className="mt-1 text-base font-semibold text-gray-900 sm:text-lg">
                                {formatAmount(summary.total_value)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs uppercase tracking-wide text-gray-500">Total cost</dt>
                            <dd className="mt-1 text-base text-gray-700 sm:text-lg">
                                {formatAmount(summary.total_cost)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs uppercase tracking-wide text-gray-500">Profit</dt>
                            <dd className="mt-1 text-base font-semibold sm:text-lg">
                                <ProfitText value={summary.total_pnl} />
                            </dd>
                        </div>
                    </dl>
                </div>
            }
        >
            <Head title={portfolio.name} />

            <div className="py-6 sm:py-10">
                <div className="mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:px-8">
                    <div className="min-w-0">
                        <div role="tablist" aria-label="Portfolio sections" className="flex gap-1 border-b border-gray-200">
                            {TABS.map((item) => (
                                <button
                                    key={item.id}
                                    type="button"
                                    role="tab"
                                    id={`tab-${item.id}`}
                                    aria-selected={tab === item.id}
                                    aria-controls={`panel-${item.id}`}
                                    onClick={() => selectTab(item.id)}
                                    className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-indigo-500 ${
                                        tab === item.id
                                            ? 'border-indigo-500 text-gray-900'
                                            : 'border-transparent text-gray-500 hover:text-gray-700'
                                    }`}
                                >
                                    {item.label}
                                </button>
                            ))}
                        </div>

                        <div
                            role="tabpanel"
                            id={`panel-${tab}`}
                            aria-labelledby={`tab-${tab}`}
                            className="pt-6"
                        >
                            {tab === 'holdings' && (
                                <HoldingsTable
                                    holdings={summary.holdings}
                                    totalValue={summary.total_value}
                                    currency={portfolio.base_currency}
                                />
                            )}
                            {/* Temporary until http_ui_tasks.md Task 10 */}
                            {tab === 'transactions' && (
                                <p className="text-sm text-gray-600">Transactions will appear here.</p>
                            )}
                            {/* Temporary until http_ui_tasks.md Task 12 */}
                            {tab === 'members' && (
                                <p className="text-sm text-gray-600">Members will appear here.</p>
                            )}
                        </div>
                    </div>

                    {/* AgentPanel goes here (agent task list) */}
                    <aside
                        aria-label="Assistant"
                        className="rounded-lg border border-dashed border-gray-300 p-5 text-sm text-gray-500"
                    >
                        The assistant will appear here.
                    </aside>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
