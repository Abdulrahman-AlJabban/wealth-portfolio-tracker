import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { formatAmount, formatDateTime, localInputToIso } from '@/lib/format';
import { AssetOption } from '@/types/portfolio';
import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

const PRIVATE_TYPE_LABELS: Record<string, string> = {
    real_estate: 'Real estate',
    cash: 'Cash',
};

function PriceCell({ asset, emptyText }: { asset: AssetOption; emptyText: string }) {
    if (asset.price === null || asset.price_as_of === null) {
        return <span className="text-gray-500">{emptyText}</span>;
    }

    return (
        <>
            <span className="font-medium text-gray-900">{formatAmount(asset.price)}</span>
            <span className="block text-xs text-gray-500">as of {formatDateTime(asset.price_as_of)}</span>
        </>
    );
}

function AddAssetForm({ portfolioId }: { portfolioId: number }) {
    const form = useForm({ name: '', type: 'real_estate' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('assets.store', portfolioId), {
            preserveScroll: true,
            onSuccess: () => form.reset('name'),
        });
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-3 sm:flex-row sm:items-start" aria-label="Add a private asset">
            <div className="sm:flex-1">
                <InputLabel htmlFor="asset-name" value="Name" />
                <TextInput
                    id="asset-name"
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                    maxLength={100}
                    required
                    aria-invalid={form.errors.name ? true : undefined}
                    aria-describedby={form.errors.name ? 'asset-name-error' : undefined}
                    className="mt-1 block w-full"
                />
                <InputError id="asset-name-error" message={form.errors.name} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="asset-type" value="Type" />
                <select
                    id="asset-type"
                    value={form.data.type}
                    onChange={(event) => form.setData('type', event.target.value)}
                    aria-invalid={form.errors.type ? true : undefined}
                    aria-describedby={form.errors.type ? 'asset-type-error' : undefined}
                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                    <option value="real_estate">Real estate</option>
                    <option value="cash">Cash</option>
                </select>
                <InputError id="asset-type-error" message={form.errors.type} className="mt-1" />
            </div>
            <div className="sm:pt-6">
                <PrimaryButton type="submit" disabled={form.processing}>
                    {form.processing ? 'Adding…' : 'Add asset'}
                </PrimaryButton>
            </div>
        </form>
    );
}

function SetValueForm({ portfolioId, asset, onDone }: { portfolioId: number; asset: AssetOption; onDone: () => void }) {
    const form = useForm({ price: '', as_of: '' });
    const idPrefix = `value-${asset.id}`;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ ...data, as_of: localInputToIso(data.as_of) }));
        form.post(route('valuations.store', [portfolioId, asset.id]), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    }

    return (
        <form onSubmit={submit} className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-start" aria-label={`Set the value of ${asset.name}`}>
            <div>
                <InputLabel htmlFor={`${idPrefix}-price`} value="Value" />
                {/* type="text" + inputMode: number inputs allow "1e3" and go through a float. */}
                <TextInput
                    id={`${idPrefix}-price`}
                    inputMode="decimal"
                    autoComplete="off"
                    value={form.data.price}
                    onChange={(event) => form.setData('price', event.target.value)}
                    required
                    isFocused
                    aria-invalid={form.errors.price ? true : undefined}
                    aria-describedby={form.errors.price ? `${idPrefix}-price-error` : undefined}
                    className="mt-1 block w-full sm:w-40"
                />
                <InputError id={`${idPrefix}-price-error`} message={form.errors.price} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor={`${idPrefix}-as_of`} value="Date (optional)" />
                <TextInput
                    id={`${idPrefix}-as_of`}
                    type="datetime-local"
                    value={form.data.as_of}
                    onChange={(event) => form.setData('as_of', event.target.value)}
                    aria-invalid={form.errors.as_of ? true : undefined}
                    aria-describedby={form.errors.as_of ? `${idPrefix}-as_of-error` : undefined}
                    className="mt-1 block w-full"
                />
                <InputError id={`${idPrefix}-as_of-error`} message={form.errors.as_of} className="mt-1" />
            </div>
            <div className="flex gap-2 sm:pt-6">
                <PrimaryButton type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save'}
                </PrimaryButton>
                <button
                    type="button"
                    onClick={onDone}
                    className="rounded px-2 text-sm text-gray-600 hover:text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                    Cancel
                </button>
            </div>
        </form>
    );
}

export default function AssetsPanel({
    portfolioId,
    assets,
    canWrite,
}: {
    portfolioId: number;
    assets: AssetOption[];
    canWrite: boolean;
}) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const privateAssets = assets.filter((asset) => asset.is_private);
    const marketAssets = assets.filter((asset) => !asset.is_private);

    return (
        <section aria-labelledby="assets-heading" className="space-y-4">
            <h3 id="assets-heading" className="text-base font-semibold text-gray-900">
                Assets
            </h3>

            <div className="rounded-lg border border-gray-200 bg-white">
                <h4 className="border-b border-gray-100 px-4 py-3 text-sm font-medium text-gray-700">
                    Private assets <span className="font-normal text-gray-500">(valued manually)</span>
                </h4>
                {privateAssets.length === 0 ? (
                    <p className="px-4 py-4 text-sm text-gray-600">No private assets yet.</p>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {privateAssets.map((asset) => (
                            <li key={asset.id} className="px-4 py-3 text-sm">
                                <div className="flex flex-wrap items-start justify-between gap-3 tabular-nums">
                                    <div>
                                        <span className="font-medium text-gray-900">{asset.name}</span>
                                        <span className="block text-xs text-gray-500">{PRIVATE_TYPE_LABELS[asset.type] ?? asset.type}</span>
                                    </div>
                                    <div className="flex items-start gap-4 text-right">
                                        <div>
                                            <PriceCell asset={asset} emptyText="No value yet" />
                                        </div>
                                        {canWrite && editingId !== asset.id && (
                                            <button
                                                type="button"
                                                onClick={() => setEditingId(asset.id)}
                                                className="rounded text-sm font-medium text-gray-700 underline-offset-2 hover:underline focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                            >
                                                Set value
                                            </button>
                                        )}
                                    </div>
                                </div>
                                {canWrite && editingId === asset.id && (
                                    <SetValueForm portfolioId={portfolioId} asset={asset} onDone={() => setEditingId(null)} />
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {canWrite && (
                    <div className="border-t border-gray-100 px-4 py-4">
                        <AddAssetForm portfolioId={portfolioId} />
                    </div>
                )}
            </div>

            <div className="rounded-lg border border-gray-200 bg-white">
                <h4 className="border-b border-gray-100 px-4 py-3 text-sm font-medium text-gray-700">
                    Market assets <span className="font-normal text-gray-500">(priced automatically, read-only)</span>
                </h4>
                <ul className="divide-y divide-gray-100">
                    {marketAssets.map((asset) => (
                        <li key={asset.id} className="flex items-start justify-between gap-3 px-4 py-3 text-sm tabular-nums">
                            <div>
                                <span className="font-medium text-gray-900">{asset.symbol ?? asset.name}</span>
                                <span className="block text-xs text-gray-500">{asset.name}</span>
                            </div>
                            <div className="text-right">
                                <PriceCell asset={asset} emptyText="No price yet" />
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
