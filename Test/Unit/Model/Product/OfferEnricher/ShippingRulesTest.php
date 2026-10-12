<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\OfferEnricher;

use MageOS\Seo\Model\Product\OfferEnricher\ShippingRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issues #32 and #33: what shipping details may be published, as Google's shipping-policy
 * reference allows them.
 */
class ShippingRulesTest extends TestCase
{
    /**
     * @return array<string,array{string,string,?string,?array<string,int>}>
     */
    public static function times(): array
    {
        return [
            'neither bound: nothing to publish' => ['', '', null, null],
            'both bounds'                       => ['2', '5', null, ['minValue' => 2, 'maxValue' => 5]],
            'equal bounds'                      => ['3', '3', null, ['minValue' => 3, 'maxValue' => 3]],
            'the same day'                      => ['0', '0', null, ['minValue' => 0, 'maxValue' => 0]],
            'a maximum alone'                   => ['', '4', null, ['maxValue' => 4]],
            'blanks around the numbers'         => [' 1 ', ' 2 ', null, ['minValue' => 1, 'maxValue' => 2]],
            'a blank maximum is none'           => ['', '  ', null, null],
            'a blank minimum is none'           => ['  ', '3', null, ['maxValue' => 3]],
            'a minimum alone'                   => ['2', '', ShippingRules::MIN_WITHOUT_MAX, null],
            'a minimum above the maximum'       => ['5', '2', ShippingRules::MIN_ABOVE_MAX, null],
            'a negative bound'                  => ['-1', '2', ShippingRules::NOT_DAYS, null],
            'a fraction'                        => ['1', '2.5', ShippingRules::NOT_DAYS, null],
            'not a number'                      => ['', 'two', ShippingRules::NOT_DAYS, null],
        ];
    }

    /**
     * @dataProvider times
     * @param string $min
     * @param string $max
     * @param string|null $problem
     * @param array<string,int>|null $bounds
     * @return void
     */
    #[DataProvider('times')]
    public function testAShippingTimeIsAMaximumWithOrWithoutAMinimum(
        string $min,
        string $max,
        ?string $problem,
        ?array $bounds
    ): void {
        $rules = new ShippingRules();

        $this->assertSame($problem, $rules->daysProblem($min, $max));
        $this->assertSame($bounds, $rules->dayBounds($min, $max));
    }

    /**
     * @return array<string,array{string,?string}>
     */
    public static function rates(): array
    {
        return [
            'an amount'         => ['4.95', null],
            'free shipping'     => ['0', null],
            'free, with cents'  => ['0.00', null],
            'no rate'           => ['', ShippingRules::NO_RATE],
            'blanks only'       => ['  ', ShippingRules::NO_RATE],
            'a negative amount' => ['-1', ShippingRules::NOT_AN_AMOUNT],
            'not a number'      => ['free', ShippingRules::NOT_AN_AMOUNT],
        ];
    }

    /**
     * @dataProvider rates
     * @param string $rate
     * @param string|null $problem
     * @return void
     */
    #[DataProvider('rates')]
    public function testARateIsAnAmountOfZeroOrMore(string $rate, ?string $problem): void
    {
        $this->assertSame($problem, (new ShippingRules())->rateProblem($rate));
    }
}
