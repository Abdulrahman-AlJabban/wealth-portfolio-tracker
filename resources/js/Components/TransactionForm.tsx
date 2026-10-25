import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { formatAmount, formatDateTime, localInputToIso } from '@/lib/format';
import { AssetOption, TransactionType } from '@/types/portfolio';
import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

const TYPES: { value: TransactionType; label: string }[] = [
    { value: 'buy', label: 'Buy' },
    { value: 'sell', label: 'Sell' },
    { value: 'deposit', label: 'Deposit' },
    { value: 'withdrawal', label: 'Withdrawal' },
];

const selectClass =
    'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';

/** aria props that tie a field to its error message, if there is one. */
function errorProps(field: string, message?: string) {
    return {
        'aria-invalid': message ? true : undefined,
        'aria-describedby': message ? `${field}-error` : undefined,
    };
}

/**
 * The asset's latest price as a suggestion for the unit price, e.g. "67123.45000000" -> "67123.45".
 * A string operation only; the user can always change it (the price is what they actually paid).
 */
function suggestedPrice(asset: AssetOption | undefined): string {
    if (asset?.price == null) return '';

    return asset.price.includes('.') ? asset.price.replace(/\.?0+$/, '') : asset.price;
}

export default function TransactionForm({
    portfolioId,
    assets,
}: {
    portfolioId: number;
    assets: AssetOption[];
}) {
    const form = useForm({
        asset_id: assets[0] ? String(assets[0].id) : '',
        type: 'buy' as TransactionType,
        quantity: '',
        price: suggestedPrice(assets[0]),
        occurred_at: '',
        note: '',
        // A new key per submit: a double click sends the same key twice, and the server logs once.
        idempotency_key: crypto.randomUUID(),
    });

    const selectedAsset = assets.find((asset) => String(asset.id) === form.data.asset_id);
    const showPriceHint = selectedAsset?.price != null && selectedAsset.price_as_of !== null;

    // Picking an asset suggests its latest price. The 30-second price refresh does not touch the field,
    // so a price the user typed is never overwritten.
    function selectAsset(assetId: string) {
        const asset = assets.find((item) => String(item.id) === assetId);
        form.setData((data) => ({ ...data, asset_id: assetId, price: suggestedPrice(asset) }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        form.transform((data) => ({ ...data, occurred_at: localInputToIso(data.occurred_at) }));

        form.post(route('transactions.store', portfolioId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('quantity', 'occurred_at', 'note');
                form.setData((data) => ({ ...data, price: suggestedPrice(selectedAsset), idempotency_key: crypto.randomUUID() }));
            },
        });
    }

    return (
        <form
            onSubmit={submit}
            className="rounded-lg border border-gray-200 bg-white p-5"
            aria-labelledby="log-transaction"
        >
            <h3 id="log-transaction" className="text-base font-medium text-gray-900">
                Log a transaction
            </h3>

            <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div className="sm:col-span-2">
                    <InputLabel htmlFor="asset_id" value="Asset" />
                    <select
                        id="asset_id"
                        value={form.data.asset_id}
                        onChange={(event) => selectAsset(event.target.value)}
                        className={selectClass}
                        {...errorProps('asset_id', form.errors.asset_id)}
                    >
                        {assets.map((asset) => (
                            <option key={asset.id} value={asset.id}>
                                {asset.symbol ? `${asset.symbol} · ${asset.name}` : asset.name}
                                {asset.is_private ? ' (private)' : ''}
                            </option>
                        ))}
                    </select>
                    <InputError id="asset_id-error" message={form.errors.asset_id} className="mt-1" />
                </div>

                <div className="sm:col-span-2">
                    <InputLabel htmlFor="type" value="Type" />
                    <select
                        id="type"
                        value={form.data.type}
                        onChange={(event) => form.setData('type', event.target.value as TransactionType)}
                        className={selectClass}
                        {...errorProps('type', form.errors.type)}
                    >
                        {TYPES.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </select>
                    <InputError id="type-error" message={form.errors.type} className="mt-1" />
                </div>

                <div>
                    <InputLabel htmlFor="quantity" value="Quantity" />
                    {/* type="text" + inputMode: number inputs allow "1e3" and go through a float. */}
                    <TextInput
                        id="quantity"
                        inputMode="decimal"
                        autoComplete="off"
                        value={form.data.quantity}
                        onChange={(event) => form.setData('quantity', event.target.value)}
                        required
                        className="mt-1 block w-full"
                        {...errorProps('quantity', form.errors.quantity)}
                    />
                    <InputError id="quantity-error" message={form.errors.quantity} className="mt-1" />
                </div>

                <div>
                    <InputLabel htmlFor="price" value="Unit price" />
                    {/* Suggested from the latest price, but it records what the user actually paid, so it stays editable. */}
                    <TextInput
                        id="price"
                        inputMode="decimal"
                        autoComplete="off"
                        value={form.data.price}
                        onChange={(event) => form.setData('price', event.target.value)}
                        required
                        className="mt-1 block w-full"
                        aria-invalid={form.errors.price ? true : undefined}
                        aria-describedby={[showPriceHint ? 'price-hint' : '', form.errors.price ? 'price-error' : ''].join(' ').trim() || undefined}
                    />
                    <InputError id="price-error" message={form.errors.price} className="mt-1" />
                    {showPriceHint && selectedAsset?.price != null && selectedAsset.price_as_of !== null && (
                        <p id="price-hint" className="mt-1 text-xs text-gray-500">
                            {selectedAsset.is_private ? 'Latest valuation' : 'Market price'} {formatAmount(selectedAsset.price)}, as of{' '}
                            {formatDateTime(selectedAsset.price_as_of)}. Change it to what you actually paid.
                        </p>
                    )}
                </div>

                <div>
                    <InputLabel htmlFor="occurred_at" value="Date (optional)" />
                    <TextInput
                        id="occurred_at"
                        type="datetime-local"
                        value={form.data.occurred_at}
                        onChange={(event) => form.setData('occurred_at', event.target.value)}
                        className="mt-1 block w-full"
                        {...errorProps('occurred_at', form.errors.occurred_at)}
                    />
                    <InputError id="occurred_at-error" message={form.errors.occurred_at} className="mt-1" />
                </div>

                <div className="sm:col-span-2 lg:col-span-4">
                    <InputLabel htmlFor="note" value="Note (optional)" />
                    <TextInput
                        id="note"
                        value={form.data.note}
                        onChange={(event) => form.setData('note', event.target.value)}
                        maxLength={500}
                        className="mt-1 block w-full"
                        {...errorProps('note', form.errors.note)}
                    />
                    <InputError id="note-error" message={form.errors.note} className="mt-1" />
                </div>
            </div>

            <div className="mt-4 flex items-center gap-3">
                <PrimaryButton type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Log transaction'}
                </PrimaryButton>
                <span className="text-xs text-gray-500">Leave the date empty to use the current time.</span>
            </div>
        </form>
    );
}
