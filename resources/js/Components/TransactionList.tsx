import { formatAmount, formatDateTime, formatQuantity } from '@/lib/format';
import { TransactionRow } from '@/types/portfolio';
import { router } from '@inertiajs/react';
import { useState } from 'react';

const TYPE_LABELS: Record<TransactionRow['type'], string> = {
    buy: 'Buy',
    sell: 'Sell',
    deposit: 'Deposit',
    withdrawal: 'Withdrawal',
};

function SourceBadge({ source }: { source: TransactionRow['source'] }) {
    const tone =
        source === 'agent'
            ? 'border-indigo-200 bg-indigo-50 text-indigo-700'
            : 'border-gray-200 bg-gray-50 text-gray-600';

    return (
        <span className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-medium ${tone}`}>
            {source === 'agent' ? 'Agent' : 'User'}
        </span>
    );
}

export default function TransactionList({
    portfolioId,
    transactions,
    canWrite,
}: {
    portfolioId: number;
    transactions: TransactionRow[];
    canWrite: boolean;
}) {
    const [confirmingId, setConfirmingId] = useState<number | null>(null);
    const [reversingId, setReversingId] = useState<number | null>(null);
    // The refusal belongs to the row that was reversed, not to the page's shared error bag.
    const [rowError, setRowError] = useState<{ id: number; message: string } | null>(null);

    function reverse(id: number) {
        setReversingId(id);
        setRowError(null);
        router.post(
            route('transactions.reverse', [portfolioId, id]),
            {},
            {
                preserveScroll: true,
                onError: (errors) =>
                    setRowError({
                        id,
                        message: errors.transaction ?? errors.quantity ?? 'This transaction could not be reversed.',
                    }),
                onFinish: () => {
                    setReversingId(null);
                    setConfirmingId(null);
                },
            },
        );
    }

    if (transactions.length === 0) {
        return <p className="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-600">No transactions yet.</p>;
    }

    return (
        <div>
            <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <caption className="sr-only">Transactions, newest first</caption>
                    <thead className="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" className="px-4 py-3">Date</th>
                            <th scope="col" className="px-4 py-3">Type</th>
                            <th scope="col" className="px-4 py-3">Asset</th>
                            <th scope="col" className="px-4 py-3 text-right">Quantity</th>
                            <th scope="col" className="px-4 py-3 text-right">Price</th>
                            <th scope="col" className="px-4 py-3">Source</th>
                            <th scope="col" className="px-4 py-3">By</th>
                            {canWrite && <th scope="col" className="px-4 py-3"><span className="sr-only">Actions</span></th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 tabular-nums">
                        {transactions.map((transaction) => {
                            const isReversal = transaction.reverses_id !== null;
                            const canReverse = canWrite && !isReversal && !transaction.reversed;
                            const struck = transaction.reversed ? 'line-through text-gray-400' : '';

                            return (
                                <tr key={transaction.id} className="align-top">
                                    <td className={`whitespace-nowrap px-4 py-3 ${struck}`}>
                                        {formatDateTime(transaction.occurred_at)}
                                        <span className="block text-xs text-gray-400">#{transaction.id}</span>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <span className={struck}>{TYPE_LABELS[transaction.type]}</span>
                                        {transaction.reversed && (
                                            <span className="block text-xs font-medium text-gray-600">Reversed</span>
                                        )}
                                        {isReversal && (
                                            <span className="block text-xs text-gray-500">Reverses #{transaction.reverses_id}</span>
                                        )}
                                    </td>
                                    <td className={`whitespace-nowrap px-4 py-3 ${struck}`}>
                                        {transaction.asset.symbol ?? transaction.asset.name}
                                        {transaction.note !== null && (
                                            <span className="block max-w-xs truncate text-xs text-gray-500" title={transaction.note}>
                                                {transaction.note}
                                            </span>
                                        )}
                                    </td>
                                    <td className={`whitespace-nowrap px-4 py-3 text-right ${struck}`}>
                                        {formatQuantity(transaction.quantity)}
                                    </td>
                                    <td className={`whitespace-nowrap px-4 py-3 text-right ${struck}`}>
                                        {formatAmount(transaction.price)}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <SourceBadge source={transaction.source} />
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-gray-600">{transaction.author.name}</td>
                                    {canWrite && (
                                        <td className="whitespace-nowrap px-4 py-3 text-right">
                                            {canReverse && confirmingId !== transaction.id && (
                                                <button
                                                    type="button"
                                                    onClick={() => setConfirmingId(transaction.id)}
                                                    className="rounded text-sm font-medium text-gray-700 underline-offset-2 hover:underline focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                                >
                                                    Reverse
                                                </button>
                                            )}
                                            {canReverse && confirmingId === transaction.id && (
                                                <span className="inline-flex items-center gap-2">
                                                    <span className="text-xs text-gray-600">Add an opposite row?</span>
                                                    <button
                                                        type="button"
                                                        onClick={() => reverse(transaction.id)}
                                                        disabled={reversingId !== null}
                                                        className="rounded bg-gray-800 px-2 py-1 text-xs font-medium text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50"
                                                    >
                                                        {reversingId === transaction.id ? 'Reversing…' : 'Confirm'}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => setConfirmingId(null)}
                                                        className="rounded px-2 py-1 text-xs text-gray-600 hover:text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                                    >
                                                        Cancel
                                                    </button>
                                                </span>
                                            )}
                                            {rowError?.id === transaction.id && (
                                                <p role="alert" className="mt-1 whitespace-normal text-xs text-red-600">
                                                    {rowError.message}
                                                </p>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            <p className="mt-2 text-xs text-gray-500">
                Showing the latest 50 transactions. Corrections are new rows: the ledger is never edited.
            </p>
        </div>
    );
}
