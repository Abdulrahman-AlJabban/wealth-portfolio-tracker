import AgentPanel from '@/Components/AgentPanel';
import AssetsPanel from '@/Components/AssetsPanel';
import HoldingsTable, { ProfitText } from '@/Components/HoldingsTable';
import MembersPanel from '@/Components/MembersPanel';
import RoleBadge from '@/Components/RoleBadge';
import TransactionForm from '@/Components/TransactionForm';
import TransactionList from '@/Components/TransactionList';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatAmount } from '@/lib/format';
import { ShowProps } from '@/types/portfolio';
import { Head } from '@inertiajs/react';
import { KeyboardEvent, useState } from 'react';

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

export default function Show({ portfolio, role, abilities, assistant, summary, transactions, assets, members }: ShowProps) {
    const [tab, setTab] = useState<TabId>(tabFromHash);

    function selectTab(id: TabId) {
        setTab(id);
        // Keep Inertia's own history state; only the hash changes, so a refresh reopens this tab.
        window.history.replaceState(window.history.state, '', `#${id}`);
    }

    // ARIA tabs pattern: Left and Right move between tabs, Home and End jump to the ends.
    function onTabKeyDown(event: KeyboardEvent<HTMLButtonElement>) {
        const index = TABS.findIndex((item) => item.id === tab);
        const targets: Record<string, number | undefined> = {
            ArrowRight: (index + 1) % TABS.length,
            ArrowLeft: (index - 1 + TABS.length) % TABS.length,
            Home: 0,
            End: TABS.length - 1,
        };
        const nextIndex = targets[event.key];

        if (nextIndex === undefined) {
            return;
        }

        event.preventDefault();
        selectTab(TABS[nextIndex].id);
        document.getElementById(`tab-${TABS[nextIndex].id}`)?.focus();
    }

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <h2 className="text-xl font-medium leading-tight text-gray-800">
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
                            <dd className="mt-1 text-base font-medium text-gray-900 sm:text-lg">
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
                            <dd className="mt-1 text-base font-medium sm:text-lg">
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
                                    tabIndex={tab === item.id ? 0 : -1}
                                    onClick={() => selectTab(item.id)}
                                    onKeyDown={onTabKeyDown}
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
                                <div className="space-y-8">
                                    <HoldingsTable
                                        holdings={summary.holdings}
                                        totalValue={summary.total_value}
                                        currency={portfolio.base_currency}
                                    />
                                    <AssetsPanel portfolioId={portfolio.id} assets={assets} canWrite={abilities.write} />
                                </div>
                            )}
                            {tab === 'transactions' && (
                                <div className="space-y-6">
                                    {/* Hidden for viewers as a convenience; the server refuses their writes anyway. */}
                                    {abilities.write && (
                                        <TransactionForm portfolioId={portfolio.id} assets={assets} />
                                    )}
                                    <TransactionList
                                        portfolioId={portfolio.id}
                                        transactions={transactions}
                                        canWrite={abilities.write}
                                    />
                                </div>
                            )}
                            {tab === 'members' && (
                                <MembersPanel portfolioId={portfolio.id} members={members} canManage={abilities.manage} />
                            )}
                        </div>
                    </div>

                    <aside aria-label="Assistant" className="lg:sticky lg:top-6 lg:self-start">
                        <AgentPanel portfolioId={portfolio.id} portfolioName={portfolio.name} configured={assistant.configured} canWrite={abilities.write} />
                    </aside>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
