<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\LlmsTxt;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Aeo\Config;
use MageOS\Seo\Model\Faq\SourcePool;
use MageOS\Seo\Model\LlmsTxt\FaqLlmsSectionProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class FaqLlmsSectionProviderTest extends TestCase
{
    /**
     * @var SourcePool&Stub
     */
    private SourcePool&Stub $sourcePool;

    protected function setUp(): void
    {
        $this->sourcePool = $this->createStub(SourcePool::class);
    }

    public function testEmptyWhenTheGroupsHaveNoFaqs(): void
    {
        $this->sourcePool->method('getFaqs')->willReturn([]);
        $provider = $this->provider(['global']);

        $this->assertSame('', $provider->getConciseSection());
        $this->assertSame('', $provider->getFullSection());
    }

    public function testFullSectionRendersAllFaqsAsMarkdown(): void
    {
        $this->sourcePool->method('getFaqs')->willReturnMap([
            ['global', 1, [
                ['question' => 'Q1', 'answer' => 'A1'],
                ['question' => 'Q2', 'answer' => 'A2'],
            ]],
        ]);

        $section = $this->provider(['global'])->getFullSection();

        $this->assertStringContainsString('- **Q1** A1', $section);
        $this->assertStringContainsString('- **Q2** A2', $section);
    }

    public function testSectionHasNoHeadingSoItLandsBeforeTheFirstH2(): void
    {
        $this->sourcePool->method('getFaqs')->willReturnMap([
            ['global', 1, [['question' => 'Q1', 'answer' => 'A1']]],
        ]);

        $section = $this->provider(['global'])->getFullSection();

        $this->assertDoesNotMatchRegularExpression('/^#/m', $section);
        $this->assertStringStartsWith('Frequently asked questions:', $section);
    }

    public function testHtmlAndLineBreaksAreReducedToOneLine(): void
    {
        $this->sourcePool->method('getFaqs')->willReturnMap([
            ['global', 1, [[
                'question' => "Do you ship\nabroad?",
                'answer'   => "<p>Yes.</p>\n<p>We ship to the EU &amp; the UK.</p>",
            ]]],
        ]);

        $section = $this->provider(['global'])->getFullSection();

        $this->assertStringContainsString('- **Do you ship abroad?** Yes. We ship to the EU & the UK.', $section);
        $this->assertCount(3, explode("\n", $section));
    }

    public function testAsterisksInQuestionsAreEscaped(): void
    {
        $this->sourcePool->method('getFaqs')->willReturnMap([
            ['global', 1, [['question' => 'What is 2*3?', 'answer' => '6']]],
        ]);

        $this->assertStringContainsString('- **What is 2\\*3?** 6', $this->provider(['global'])->getFullSection());
    }

    public function testConciseSectionLimitsToFiveEntries(): void
    {
        $faqs = [];
        for ($i = 1; $i <= 8; $i++) {
            $faqs[] = ['question' => "Q$i", 'answer' => "A$i"];
        }
        $this->sourcePool->method('getFaqs')->willReturn($faqs);

        $section = $this->provider(['global'])->getConciseSection();

        $this->assertStringContainsString('**Q5**', $section);
        $this->assertStringNotContainsString('**Q6**', $section);
    }

    public function testTheGroupsFollowTheConfiguredOrder(): void
    {
        $this->sourcePool->method('getFaqs')->willReturnMap([
            ['shipping', 1, [['question' => 'Shipping Q', 'answer' => 'A']]],
            ['global', 1, [['question' => 'Global Q', 'answer' => 'A']]],
        ]);

        $section = $this->provider(['shipping', 'global'])->getFullSection();

        $this->assertLessThan(
            strpos($section, '**Global Q**'),
            strpos($section, '**Shipping Q**')
        );
    }

    public function testTheConciseLimitAppliesAcrossGroups(): void
    {
        $this->sourcePool->method('getFaqs')->willReturnMap([
            ['global', 1, [
                ['question' => 'G1', 'answer' => 'A'],
                ['question' => 'G2', 'answer' => 'A'],
                ['question' => 'G3', 'answer' => 'A'],
            ]],
            ['shipping', 1, [
                ['question' => 'S1', 'answer' => 'A'],
                ['question' => 'S2', 'answer' => 'A'],
                ['question' => 'S3', 'answer' => 'A'],
            ]],
        ]);

        $section = $this->provider(['global', 'shipping'])->getConciseSection();

        $this->assertStringContainsString('**S2**', $section);
        $this->assertStringNotContainsString('**S3**', $section);
    }

    public function testNoGroupsSelectedIsNoSection(): void
    {
        $sourcePool = $this->createMock(SourcePool::class);
        $sourcePool->expects($this->never())->method('getFaqs');
        $provider = $this->provider([], $sourcePool);

        $this->assertSame('', $provider->getConciseSection());
        $this->assertSame('', $provider->getFullSection());
    }

    /**
     * The provider for store view 1, with the given FAQ groups configured.
     *
     * @param string[] $groups
     * @param SourcePool|null $sourcePool the test's stub when null
     * @return FaqLlmsSectionProvider
     */
    private function provider(array $groups, ?SourcePool $sourcePool = null): FaqLlmsSectionProvider
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $config = $this->createStub(Config::class);
        $config->method('getLlmsFaqGroups')->willReturnMap([[1, $groups]]);

        return new FaqLlmsSectionProvider($sourcePool ?? $this->sourcePool, $storeManager, $config);
    }
}
