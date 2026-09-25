<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product;

use MageOS\Seo\Model\Product\GtinValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GtinValidatorTest extends TestCase
{
    private GtinValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new GtinValidator();
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function gtinProvider(): array
    {
        return [
            'valid gtin13 (EAN-13)'        => ['4006381333931', 'gtin13'],
            'valid gtin12 (UPC-A)'         => ['036000291452', 'gtin12'],
            'valid gtin8 (EAN-8)'          => ['96385074', 'gtin8'],
            'valid gtin14'                 => ['10614141543219', 'gtin14'],
            'valid with hyphens'           => ['4-006381-333931', 'gtin13'],
            'valid with spaces'            => ['4006381 333931', 'gtin13'],
            'wrong check digit'            => ['4006381333932', null],
            'wrong length (10 digits)'     => ['1234567890', null],
            'internal sku'                 => ['SKU-12345', null],
            'empty string'                 => ['', null],
            'letters only'                 => ['ABCDEFGHIJKLM', null],
            'isbn-13 with valid checksum'  => ['9780306406157', 'gtin13'],
        ];
    }

    /**
     * @dataProvider gtinProvider
     */
    #[DataProvider('gtinProvider')] // data provider for the  function
    public function testResolveProperty(string $value, ?string $expected): void
    {
        $this->assertSame($expected, $this->validator->resolveProperty($value));
    }

    public function testNormalizeStripsFormatting(): void
    {
        $this->assertSame('4006381333931', $this->validator->normalize(' 4-006381-333931 '));
    }

    public function testNormalizeRejectsNonDigits(): void
    {
        $this->assertNull($this->validator->normalize('SKU_123'));
    }

    public function testToPropertiesGivesTheLengthsPropertyWithBareDigits(): void
    {
        $this->assertSame(['gtin13' => '4006381333931'], $this->validator->toProperties(' 4-006381-333931 '));
    }

    public function testToPropertiesIsEmptyForAValueThatDoesNotValidate(): void
    {
        $this->assertSame([], $this->validator->toProperties('5901234123450'));
    }
}
