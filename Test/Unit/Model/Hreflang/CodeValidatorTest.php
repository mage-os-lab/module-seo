<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang;

use MageOS\Seo\Model\Hreflang\CodeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CodeValidatorTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function normaliseProvider(): array
    {
        return [
            'magento locale'        => ['en_GB', 'en-GB'],
            'all lower case'        => ['en-gb', 'en-GB'],
            'all upper case'        => ['EN-GB', 'en-GB'],
            'language only'         => ['DE', 'de'],
            'script'                => ['zh-hant', 'zh-Hant'],
            'script and region'     => ['ZH_HANT_tw', 'zh-Hant-TW'],
            'surrounding space'     => ['  fr-ca ', 'fr-CA'],
        ];
    }

    /**
     * @dataProvider normaliseProvider
     */
    #[DataProvider('normaliseProvider')]
    public function testNormalise(string $entered, string $expected): void
    {
        $this->assertSame($expected, (new CodeValidator())->normalise($entered));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function validityProvider(): array
    {
        return [
            'language'                   => ['en', true],
            'language and region'        => ['en-GB', true],
            'language and script'        => ['zh-Hant', true],
            'language, script, region'   => ['zh-Hant-TW', true],
            'three-letter language'      => ['eng-GB', false],
            'three-letter region'        => ['en-GBR', false],
            'UN M.49 region'             => ['es-419', false],
            'region before script'       => ['zh-TW-Hant', false],
            'x-default is not a code'    => ['x-default', false],
            'empty'                      => ['', false],
            'not normalised'             => ['en_GB', false],
        ];
    }

    /**
     * @dataProvider validityProvider
     */
    #[DataProvider('validityProvider')]
    public function testIsValid(string $code, bool $expected): void
    {
        $this->assertSame($expected, (new CodeValidator())->isValid($code));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function regionProvider(): array
    {
        return [
            'language only'     => ['en', null],
            'language, region'  => ['en-GB', 'GB'],
            'script, no region' => ['zh-Hant', null],
            'script and region' => ['zh-Hant-TW', 'TW'],
        ];
    }

    /**
     * @dataProvider regionProvider
     */
    #[DataProvider('regionProvider')]
    public function testRegion(string $code, ?string $expected): void
    {
        $this->assertSame($expected, (new CodeValidator())->region($code));
    }
}
