<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Config\Source;

use MageOS\Seo\Api\FaqSourceProviderInterface;
use MageOS\Seo\Model\Config\Source\FaqGroups;
use MageOS\Seo\Model\Faq\SourcePool;
use PHPUnit\Framework\TestCase;

/**
 * The FAQ groups offered for the llms documents: those every FAQ source has, plus the `global`
 * default.
 */
class FaqGroupsTest extends TestCase
{
    public function testTheGroupsInUseAndGlobalSortedOnceEach(): void
    {
        $this->assertSame(
            ['global', 'returns', 'shipping'],
            array_column($this->source(['shipping', 'global', 'returns'])->toOptionArray(), 'value')
        );
    }

    public function testAnotherSourcesGroupsAreOfferedToo(): void
    {
        // A module's own FAQ source registered in the pool, beside the FAQ table.
        $this->assertSame(
            ['global', 'product-care', 'shipping'],
            array_column($this->source(['shipping'], ['product-care'])->toOptionArray(), 'value')
        );
    }

    public function testGlobalIsOfferedWithNoFaqs(): void
    {
        $this->assertSame(
            [['value' => 'global', 'label' => 'global']],
            $this->source([])->toOptionArray()
        );
    }

    public function testEachGroupIsItsOwnLabel(): void
    {
        foreach ($this->source(['returns'])->toOptionArray() as $option) {
            $this->assertSame($option['value'], $option['label']);
        }
    }

    /**
     * The option source over a pool of sources with the given groups.
     *
     * @param string[] ...$groupsPerSource
     * @return FaqGroups
     */
    private function source(array ...$groupsPerSource): FaqGroups
    {
        $sources = [];
        foreach ($groupsPerSource as $groups) {
            $source = $this->createStub(FaqSourceProviderInterface::class);
            $source->method('getIdentifiers')->willReturn($groups);
            $sources[] = $source;
        }

        return new FaqGroups(new SourcePool($sources));
    }
}
