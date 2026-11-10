<?php
namespace App\Services;
/** Decimal string arithmetic for exact inventory and monetary calculations. */
final class Decimal {
    public static function add($a, $b, int $scale = 3): string { return bcadd((string)$a, (string)$b, $scale); }
    public static function sub($a, $b, int $scale = 3): string { return bcsub((string)$a, (string)$b, $scale); }
    public static function mul($a, $b, int $scale = 3): string { return bcmul((string)$a, (string)$b, $scale); }
    public static function div($a, $b, int $scale = 6): string { return bccomp((string)$b, '0', 6) === 0 ? '0' : bcdiv((string)$a, (string)$b, $scale); }
    public static function compare($a, $b): int { return bccomp((string)$a, (string)$b, 6); }
    public static function money($value): string { return bcadd((string)$value, bccomp((string)$value, '0', 6) < 0 ? '-0.5' : '0.5', 0); }
}
