<?php
namespace Tests\Unit;
use App\Services\Decimal;
use PHPUnit\Framework\TestCase;
class DecimalTest extends TestCase {
    public function test_decimal_arithmetic_and_idr_rounding_are_exact(): void {
        $this->assertSame('0.300',Decimal::add('0.1','0.2'));
        $this->assertSame('522',Decimal::money(Decimal::mul('2.321','225.07',6)));
        $this->assertSame('2',Decimal::money('1.5'));
        $this->assertSame('-2',Decimal::money('-1.5'));
        $this->assertSame('0',Decimal::div('1','0'));
    }
}
