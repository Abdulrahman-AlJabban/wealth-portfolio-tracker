import {
    formatAmount,
    formatDateTime,
    formatQuantity,
    formatSigned,
    isNegative,
    isZero,
} from '@/lib/format';
import { Holding } from '@/types/portfolio';

/**
 * Bar width only, DISPLAY ONLY. This is the one place the UI turns money into a JS number,
 * because a CSS width needs one. Nothing is computed from it.
 */
function allocationPercent(value: string, totalValue: string): number {
    const total = Number(totalValue);

    return total > 0 ? (Number(value) / total) * 100 : 0;
}

export function ProfitText({ value }: { value: string }) {
    // The sign from formatSigned carries the meaning; the color only repeats it.
    const tone = isNegative(value)
        ? 'text-red-700'
        : isZero(value)
          ? 'text-gray-700'
          : 'text-green-700';

    return <span className={tone}>{formatSigned(value)}</span>;
}

export default function HoldingsTable({
    holdings,
    totalValue,
    currency,
}: {
    holdings: Holding[];
    totalValue: string;
    currency: string;
}) {
    if (holdings.length === 0) {
        return (
            <p className="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-600">
                No holdings yet. Log your first buy in the Transactions tab.
            </p>
        );
    }

    return (
        <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table className="min-w-full divide-y divide-gray-200 text-sm">
                <caption className="sr-only">
                    Holdings, valued in {currency}
                </caption>
                <thead className="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                    <tr>
                        <th scope="col" className="px-4 py-3">Asset</th>
                        <th scope="col" className="px-4 py-3 text-right">Quantity</th>
                        <th scope="col" className="px-4 py-3 text-right">Price</th>
                        <th scope="col" className="px-4 py-3 text-right">Value</th>
                        <th scope="col" className="px-4 py-3 text-right">Profit</th>
                        <th scope="col" className="px-4 py-3">Allocation</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 tabular-nums">
                    {holdings.map((holding) => {
                        const percent = allocationPercent(holding.value, totalValue);

                        return (
                            <tr key={holding.asset_id}>
                                <td className="whitespace-nowrap px-4 py-3">
                                    <span className="font-medium text-gray-900">
                                        {holding.symbol ?? holding.name}
                                    </span>
                                    {holding.symbol !== null && (
                                        <span className="block text-xs text-gray-500">
                                            {holding.name}
                                        </span>
                                    )}
                                </td>
                                <td className="whitespace-nowrap px-4 py-3 text-right">
                                    {formatQuantity(holding.quantity)}
                                </td>
                                <td className="whitespace-nowrap px-4 py-3 text-right">
                                    {holding.price_as_of === null ? (
                                        <span className="text-gray-500">No price yet</span>
                                    ) : (
                                        <>
                                            {formatAmount(holding.price)}
                                            <span className="block text-xs text-gray-500">
                                                as of {formatDateTime(holding.price_as_of)}
                                            </span>
                                        </>
                                    )}
                                </td>
                                <td className="whitespace-nowrap px-4 py-3 text-right font-medium text-gray-900">
                                    {formatAmount(holding.value)}
                                </td>
                                <td className="whitespace-nowrap px-4 py-3 text-right">
                                    <ProfitText value={holding.pnl} />
                                </td>
                                <td className="px-4 py-3">
                                    <div className="flex items-center gap-2">
                                        <div
                                            className="h-2 w-24 overflow-hidden rounded-full bg-gray-100"
                                            aria-hidden="true"
                                        >
                                            <div
                                                className="h-full rounded-full bg-indigo-500"
                                                style={{ width: `${percent}%` }}
                                            />
                                        </div>
                                        <span className="w-12 text-right text-xs text-gray-600">
                                            {percent.toFixed(1)}%
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
