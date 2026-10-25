import { PageProps } from './';

/*
 * The contract for the props sent by PortfolioController. Every money or quantity field is a
 * decimal string (8 places) exactly as the server computed it. Never do arithmetic on them.
 */

export type Role = 'owner' | 'editor' | 'viewer';

export type TransactionType = 'buy' | 'sell' | 'deposit' | 'withdrawal';

export type TransactionSource = 'user' | 'agent';

export type AssetType = 'stock' | 'crypto' | 'real_estate' | 'cash';

export type Abilities = {
    write: boolean;
    manage: boolean;
};

export type Holding = {
    asset_id: number;
    symbol: string | null;
    name: string;
    type: AssetType;
    quantity: string;
    price: string;
    price_as_of: string | null;
    value: string;
    cost: string;
    pnl: string;
};

export type Summary = {
    holdings: Holding[];
    total_value: string;
    total_cost: string;
    total_pnl: string;
};

export type TransactionRow = {
    id: number;
    type: TransactionType;
    quantity: string;
    price: string;
    fee: string;
    occurred_at: string;
    source: TransactionSource;
    note: string | null;
    asset: { id: number; symbol: string | null; name: string };
    author: { id: number; name: string };
    reverses_id: number | null;
    reversed: boolean;
};

export type AssetOption = {
    id: number;
    symbol: string | null;
    name: string;
    type: AssetType;
    is_private: boolean;
    /** Latest snapshot: the sync job's price for market assets, the last manual value for private ones. */
    price: string | null;
    price_as_of: string | null;
};

export type MemberRow = {
    id: number;
    role: Role;
    user: { id: number; name: string; email: string };
};

export type PortfolioListItem = {
    id: number;
    name: string;
    base_currency: string;
    role: Role;
};

export type ShowProps = PageProps<{
    portfolio: { id: number; name: string; base_currency: string };
    role: Role;
    abilities: Abilities;
    summary: Summary;
    transactions: TransactionRow[];
    assets: AssetOption[];
    members: MemberRow[];
}>;

export type IndexProps = PageProps<{
    portfolios: PortfolioListItem[];
}>;
