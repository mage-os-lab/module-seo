<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Faq;

use MageOS\Seo\Api\FaqSourceProviderInterface;
use MageOS\Seo\Model\Faq\SourcePool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SourcePoolTest extends TestCase
{
    /**
     * @param array<int, array{question: string, answer: string}> $faqs
     */
    private function makeSource(array $faqs): FaqSourceProviderInterface&MockObject
    {
        $source = $this->createMock(FaqSourceProviderInterface::class);
        $source->method('getFaqs')->willReturn($faqs);
        return $source;
    }

    public function testEmptyPoolReturnsEmpty(): void
    {
        $this->assertSame([], (new SourcePool([]))->getFaqs('shipping', 1));
    }

    public function testAggregatesAcrossSources(): void
    {
        $pool = new SourcePool([
            $this->makeSource([['question' => 'Q1', 'answer' => 'A1']]),
            $this->makeSource([['question' => 'Q2', 'answer' => 'A2']]),
        ]);
        $this->assertCount(2, $pool->getFaqs('shipping', 1));
    }

    public function testEntriesMissingQuestionOrAnswerAreSkipped(): void
    {
        $pool = new SourcePool([
            $this->makeSource([
                ['question' => '', 'answer' => 'A1'],
                ['question' => 'Q2', 'answer' => ''],
                ['question' => 'Q3', 'answer' => 'A3'],
            ]),
        ]);
        $faqs = $pool->getFaqs('shipping', 1);
        $this->assertCount(1, $faqs);
        $this->assertSame('Q3', $faqs[0]['question']);
    }

    public function testNonSourceObjectsAreSkipped(): void
    {
        $this->assertSame([], (new SourcePool([new \stdClass(), 'nope']))->getFaqs('x', 1));
    }

    public function testTheGroupsAreEverySourcesSortedOnceEach(): void
    {
        $pool = new SourcePool([
            $this->sourceWithGroups(['shipping', 'global']),
            new \stdClass(),
            $this->sourceWithGroups(['returns', 'shipping']),
        ]);

        $this->assertSame(['global', 'returns', 'shipping'], $pool->getIdentifiers());
    }

    public function testAnEmptyPoolHasNoGroups(): void
    {
        $this->assertSame([], (new SourcePool([]))->getIdentifiers());
    }

    /**
     * @param string[] $identifiers
     * @return FaqSourceProviderInterface
     */
    private function sourceWithGroups(array $identifiers): FaqSourceProviderInterface
    {
        $source = $this->createStub(FaqSourceProviderInterface::class);
        $source->method('getIdentifiers')->willReturn($identifiers);

        return $source;
    }
}
