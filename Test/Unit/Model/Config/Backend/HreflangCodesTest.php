<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use MageOS\Seo\Model\Config\Backend\HreflangCodes;
use MageOS\Seo\Model\Hreflang\CodeList;
use PHPUnit\Framework\TestCase;

/**
 * Saving a store view's hreflang codes: refused when a code is malformed, repeated or names a region
 * that does not exist; otherwise stored as the normalised list.
 */
class HreflangCodesTest extends TestCase
{
    public function testTheEnteredListIsCheckedAndStoredNormalised(): void
    {
        $codeList = $this->createMock(CodeList::class);
        $codeList->expects($this->once())->method('check')->with(['en-gb', ' fr'])
            ->willReturn($this->checked(['en-GB', 'fr']));
        $backend = $this->backend('en-gb, fr', $codeList);

        $this->assertSame($backend, $backend->beforeSave());
        $this->assertSame('en-GB,fr', $backend->getValue());
    }

    public function testAnEmptyValueIsCheckedAsOneEmptyEntryAndStoredEmpty(): void
    {
        $codeList = $this->createMock(CodeList::class);
        $codeList->expects($this->once())->method('check')->with([''])->willReturn($this->checked([]));
        $backend = $this->backend(null, $codeList);

        $backend->beforeSave();

        $this->assertSame('', $backend->getValue());
    }

    public function testMalformedCodesAreRefusedByName(): void
    {
        $backend = $this->backend('english', $this->codeList($this->checked([], ['malformed' => ['english', 'e']])));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'These hreflang codes are not valid: english, e. Use a two-letter language, optionally followed by'
            . ' a four-letter script and a two-letter region — for example en, en-GB or zh-Hant-TW.'
        );

        $backend->beforeSave();
    }

    public function testARepeatedCodeIsRefusedByName(): void
    {
        $backend = $this->backend('fr,fr', $this->codeList($this->checked(['fr'], ['duplicates' => ['fr']])));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Each hreflang code can be listed once: fr.');

        $backend->beforeSave();
    }

    public function testAnUnknownRegionIsRefusedByName(): void
    {
        $backend = $this->backend('en-UK', $this->codeList($this->checked([], ['unknown_regions' => ['en-UK']])));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'These regions are not ISO 3166-1 country codes: en-UK. The United Kingdom, for instance, is GB'
            . ' rather than UK.'
        );

        $backend->beforeSave();
    }

    public function testMalformedCodesAreReportedBeforeRepeatsAndRepeatsBeforeRegions(): void
    {
        $everything = ['malformed' => ['x'], 'duplicates' => ['fr'], 'unknown_regions' => ['en-UK']];

        try {
            $this->backend('x', $this->codeList($this->checked([], $everything)))->beforeSave();
            $this->fail('The save was not refused.');
        } catch (LocalizedException $e) {
            $this->assertStringStartsWith('These hreflang codes are not valid: x.', $e->getMessage());
        }

        unset($everything['malformed']);
        try {
            $this->backend('fr', $this->codeList($this->checked([], $everything)))->beforeSave();
            $this->fail('The save was not refused.');
        } catch (LocalizedException $e) {
            $this->assertSame('Each hreflang code can be listed once: fr.', $e->getMessage());
        }
    }

    public function testARefusedSaveLeavesTheValueAsEntered(): void
    {
        $backend = $this->backend('en-UK', $this->codeList($this->checked([], ['unknown_regions' => ['en-UK']])));

        try {
            $backend->beforeSave();
        } catch (LocalizedException) {
            // Refused, as the previous test shows.
        }

        $this->assertSame('en-UK', $backend->getValue());
    }

    /**
     * The backend model over an entered value.
     *
     * @param string|null $value
     * @param CodeList $codeList
     * @return HreflangCodes
     */
    private function backend(?string $value, CodeList $codeList): HreflangCodes
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));

        $backend = new HreflangCodes(
            $context,
            $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(TypeListInterface::class),
            $codeList
        );
        $backend->setValue($value);

        return $backend;
    }

    /**
     * A code list whose check gives the result.
     *
     * @param array<string,string[]> $result
     * @return CodeList
     */
    private function codeList(array $result): CodeList
    {
        $codeList = $this->createStub(CodeList::class);
        $codeList->method('check')->willReturn($result);

        return $codeList;
    }

    /**
     * CodeList::check()'s result: the accepted codes, and any problems found.
     *
     * @param string[] $codes
     * @param array<string,string[]> $problems
     * @return array<string,string[]>
     */
    private function checked(array $codes, array $problems = []): array
    {
        return array_merge(
            ['codes' => $codes, 'rejected' => [], 'malformed' => [], 'duplicates' => [], 'unknown_regions' => []],
            $problems
        );
    }
}
