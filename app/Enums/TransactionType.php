<?php

namespace App\Enums;

enum TransactionType: string
{
    case Buy = 'buy';
    case Sell = 'sell';
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';

    public function isOutflow(): bool
    {
        return in_array($this, [self::Sell, self::Withdrawal], true);
    }

    public function opposite(): self
    {
        return match ($this) {
            self::Buy => self::Sell,
            self::Sell => self::Buy,
            self::Deposit => self::Withdrawal,
            self::Withdrawal => self::Deposit,
        };
    }
}
