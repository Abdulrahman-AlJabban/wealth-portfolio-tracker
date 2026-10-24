<?php

namespace App\Enums;

enum PriceSource: string
{
    case Market = 'market';
    case Manual = 'manual';
}
