<?php

namespace App\Enums;

enum TransactionSource: string
{
    case User = 'user';
    case Agent = 'agent';
}
