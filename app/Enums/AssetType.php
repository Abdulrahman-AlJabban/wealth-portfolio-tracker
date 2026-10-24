<?php

namespace App\Enums;

enum AssetType: string
{
    case Stock = 'stock';
    case Crypto = 'crypto';
    case RealEstate = 'real_estate';
    case Cash = 'cash';
}
