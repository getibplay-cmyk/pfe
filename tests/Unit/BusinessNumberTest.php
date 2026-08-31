<?php

namespace Tests\Unit;

use App\Support\Ui\BusinessNumber;
use App\Support\Ui\UiLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BusinessNumberTest extends TestCase
{
    #[DataProvider('planningValues')]
    public function test_hgb_planning_units_reuse_the_canonical_decimal_converter(
        string|float $raw,
        string $expected,
    ): void {
        $this->assertSame($expected, BusinessNumber::planningVehicles($raw));
    }

    public static function planningValues(): array
    {
        return [
            'zero' => ['0', '0 véhicule'],
            'small fraction' => ['0.01', '1 véhicule'],
            'integer' => ['7', '7 véhicules'],
            'fraction' => ['7.2', '8 véhicules'],
            'six decimals' => ['19.263290', '20 véhicules'],
            'negative clamped by planning rule' => ['-1.5', '0 véhicule'],
            'NaN' => [NAN, BusinessNumber::UNAVAILABLE],
            'infinity' => [INF, BusinessNumber::UNAVAILABLE],
            'invalid' => ['invalid', BusinessNumber::UNAVAILABLE],
        ];
    }

    public function test_discrete_counts_are_french_integers_with_pluralisation(): void
    {
        $this->assertSame('19 263', BusinessNumber::integer('19263.000'));
        $this->assertSame(BusinessNumber::UNAVAILABLE, BusinessNumber::integer('7.2'));
        $this->assertSame('0 véhicule', BusinessNumber::count(0, 'véhicule'));
        $this->assertSame('1 véhicule', BusinessNumber::count(1, 'véhicule'));
        $this->assertSame('2 véhicules', BusinessNumber::count(2, 'véhicule'));
        $this->assertSame(BusinessNumber::UNAVAILABLE, BusinessNumber::count(-1, 'véhicule'));
    }

    public function test_rates_confidence_money_and_distances_keep_their_meaning(): void
    {
        $this->assertSame('33,4 %', BusinessNumber::percentage('33.35'));
        $this->assertSame('33,33 %', BusinessNumber::ratioPercentage(1, 3));
        $this->assertSame('Indisponible', BusinessNumber::ratioPercentage(1, 0));
        $this->assertSame('Indisponible', BusinessNumber::ratioPercentage(4, 3));
        $this->assertSame('7,2', BusinessNumber::average('7.200000'));
        $this->assertSame('7,20', BusinessNumber::average('7.200000', 2, 2));
        $this->assertSame('84,27 %', BusinessNumber::confidence('0.84273'));
        $this->assertSame('50,00 %', BusinessNumber::confidence('0.5'));
        $this->assertSame('84,77 %', BusinessNumber::complementConfidence('0.152342'));
        $this->assertSame('100,00 %', BusinessNumber::complementConfidence('0'));
        $this->assertSame('0,00 %', BusinessNumber::complementConfidence('1.000000'));
        $this->assertSame('0,8427', BusinessNumber::scientificDecimal('0.842735', 4));
        $this->assertSame('0,0250', BusinessNumber::scientificDecimal('0.025', 4, 4));
        $this->assertSame('+1,25', BusinessNumber::signedScientificDecimal('1.250000'));
        $this->assertSame('-1,25', BusinessNumber::signedScientificDecimal('-1.250000'));
        $this->assertSame('0', BusinessNumber::signedScientificDecimal('0.000000'));
        $this->assertSame('12 345,50 MAD', BusinessNumber::money('12345.5', 'mad'));
        $this->assertSame('12 345,50 MAD', UiLabel::money('12345.5', 'mad'));
        $this->assertSame('87,4 km', BusinessNumber::distance('87.400'));
        $this->assertSame('87,425 km', BusinessNumber::distance('87.425'));
        $this->assertSame(BusinessNumber::UNAVAILABLE, BusinessNumber::distance('87.4259'));
    }

    public function test_invalid_non_finite_and_out_of_range_display_values_are_unavailable(): void
    {
        $this->assertSame(BusinessNumber::UNAVAILABLE, BusinessNumber::percentage(INF));
        $this->assertSame(BusinessNumber::UNAVAILABLE, BusinessNumber::percentage('-1'));
        $this->assertSame(BusinessNumber::UNAVAILABLE, BusinessNumber::confidence(NAN));
        $this->assertSame(BusinessNumber::UNAVAILABLE, BusinessNumber::confidence('1.01'));
        $this->assertSame(BusinessNumber::UNAVAILABLE, BusinessNumber::complementConfidence('-0.01'));
        $this->assertSame(BusinessNumber::UNAVAILABLE, BusinessNumber::scientificDecimal('NaN'));
        $this->assertSame(BusinessNumber::UNAVAILABLE, BusinessNumber::money('1.234', 'MAD'));
        $this->assertSame(BusinessNumber::UNAVAILABLE, BusinessNumber::distance('-1'));
    }

    public function test_progress_uses_a_real_denominator_and_one_shared_percentage(): void
    {
        $this->assertSame([
            'value' => '4',
            'max' => '6',
            'percentage' => '66,7 %',
            'width' => '66.7',
        ], BusinessNumber::progress(4, 6));
        $this->assertSame('100 %', BusinessNumber::progress(8, 6)['percentage']);
        $this->assertSame('0 %', BusinessNumber::progress(-1, 6)['percentage']);
        $this->assertNull(BusinessNumber::progress(INF, 6));
        $this->assertNull(BusinessNumber::progress(1, 0));
    }
}
