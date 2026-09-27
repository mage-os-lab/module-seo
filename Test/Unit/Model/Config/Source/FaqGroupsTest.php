<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Config\Source;

use MageOS\Seo\Model\Config\Source\FaqGroups;
use MageOS\Seo\Model\Faq\Repository as FaqRepository;
use PHPUnit\Framework\TestCase;

/**
 * The FAQ groups offered for the llms documents: those in use, plus the `global` default.
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
     * @param string[] $identifiers
     * @return FaqGroups
     */
    private function source(array $identifiers): FaqGroups
    {
        $repository = $this->createStub(FaqRepository::class);
        $repository->method('getIdentifiers')->willReturn($identifiers);

        return new FaqGroups($repository);
    }
}
