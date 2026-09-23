<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang;

use Magento\Directory\Model\ResourceModel\Country\Collection;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory;
use Magento\Framework\DataObject;
use MageOS\Seo\Model\Hreflang\CodeList;
use MageOS\Seo\Model\Hreflang\CodeValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * How a list is sorted into codes and problems. That the country directory really rejects `UK` is
 * covered against the real directory by Test/Integration/Model/Config/Backend/HreflangCodesTest.
 *
 * The collection factory is one of Magento's generated classes, so this test needs an
 * installation to have generated it. The mutation-testing run works from the module directory
 * alone and excludes this group; the unit job, which runs inside an installation, does not.
 *
 * @group magento-generated
 */
#[Group('magento-generated')]
class CodeListTest extends TestCase
{
    /**
     * Country codes the directory knows.
     *
     * @var string[]
     */
    private array $countries = ['GB', 'IE', 'US', 'TW', 'MX'];

    public function testCodesAreNormalisedAndKeptInOrder(): void
    {
        $this->assertSame(
            ['en-IE', 'en-GB', 'zh-Hant-TW', 'de'],
            $this->check(['en_ie', ' en-gb ', 'zh-hant-tw', 'DE', ''])['codes']
        );
    }

    public function testAMalformedCodeIsRejectedAsEntered(): void
    {
        $checked = $this->check(['en-GB', 'english']);

        $this->assertSame(['en-GB'], $checked['codes']);
        $this->assertSame(['english'], $checked['malformed']);
        $this->assertSame(['english'], $checked['rejected']);
    }

    public function testACodeInAnUnknownRegionIsRejectedAsEntered(): void
    {
        $checked = $this->check(['en-gb', 'en-uk']);

        $this->assertSame(['en-GB'], $checked['codes']);
        $this->assertSame(['UK'], $checked['unknown_regions']);
        $this->assertSame(['en-uk'], $checked['rejected']);
    }

    public function testADuplicateIsReportedAndKeptOnce(): void
    {
        // Whatever the spelling, and including an exact repeat.
        $checked = $this->check(['en-GB', 'en_gb', 'en-GB', 'en-IE']);

        $this->assertSame(['en-GB', 'en-IE'], $checked['codes']);
        $this->assertSame(['en-GB'], $checked['duplicates']);
        $this->assertSame([], $checked['rejected']);
    }

    public function testCodesWithoutARegionNeedNoDirectory(): void
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects($this->never())->method('create');

        $checked = (new CodeList(new CodeValidator(), $factory))->check(['en', 'zh-Hant']);

        $this->assertSame(['en', 'zh-Hant'], $checked['codes']);
    }

    /**
     * Check a list against a directory holding $this->countries.
     *
     * @param string[] $entered
     * @return array{
     *     codes: string[],
     *     rejected: string[],
     *     malformed: string[],
     *     duplicates: string[],
     *     unknown_regions: string[]
     * }
     */
    private function check(array $entered): array
    {
        $filtered   = [];
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, array $condition) use (&$filtered, $collection): Collection {
                $filtered = $condition['in'] ?? [];
                return $collection;
            }
        );
        $collection->method('getIterator')->willReturnCallback(
            function () use (&$filtered): \ArrayIterator {
                return new \ArrayIterator(array_map(
                    static fn (string $id): DataObject => new DataObject(['id' => $id]),
                    array_values(array_intersect($filtered, $this->countries))
                ));
            }
        );

        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return (new CodeList(new CodeValidator(), $factory))->check($entered);
    }
}
