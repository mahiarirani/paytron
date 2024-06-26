<?php

namespace App\Models;

class Currency
{
    public const IRT = 'IRT';
    public const TRX = 'TRX';
    public const USDT = 'USDT.TRC20';
    public const PM = 'PERFECT_MONEY';
    public const PRECISION = [
        self::TRX => 6
    ];
}