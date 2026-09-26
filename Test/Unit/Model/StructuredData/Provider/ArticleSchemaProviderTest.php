<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\StructuredData\Provider;

use Magento\Framework\View\Layout;
use Magento\Framework\View\Layout\ProcessorInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\ArticleDataProviderInterface;
use MageOS\Seo\Model\Pool\HandleMatcher;
use MageOS\Seo\Model\StructuredData\OrganisationId;
use MageOS\Seo\Model\StructuredData\Provider\ArticleSchemaProvider;
use MageOS\Seo\Model\StructuredData\SpeakableSpecification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ArticleSchemaProviderTest extends TestCase
{
    /**
     * @var ProcessorInterface&MockObject
     */
    private ProcessorInterface&MockObject $layoutUpdate;

    /**
     * @var Layout&MockObject
     */
    private Layout&MockObject $layout;

    /**
     * @var OrganisationId&MockObject
     */
    private OrganisationId&MockObject $organisationId;

    protected function setUp(): void
    {
        $this->layout       = $this->createMock(Layout::class);
        $this->layoutUpdate = $this->createMock(ProcessorInterface::class);
        $this->layout->method('getUpdate')->willReturn($this->layoutUpdate);
        $this->layoutUpdate->method('getHandles')->willReturn(['blog_post_view']);
        $this->organisationId = $this->createMock(OrganisationId::class);
        $this->organisationId->method('getId')->willReturn('https://acme.com/#organization');
    }

    /**
     * @param array<string, mixed>|null $article
     * @param string[] $handles
     */
    private function makeDataProvider(
        ?array $article,
        array $handles = ['blog_post_view']
    ): ArticleDataProviderInterface&MockObject {
        $provider = $this->createMock(ArticleDataProviderInterface::class);
        $provider->method('getHandles')->willReturn($handles);
        $provider->method('getArticle')->willReturn($article);
        return $provider;
    }

    /**
     * @param array<int, ArticleDataProviderInterface&MockObject> $providers
     * @param array<string, mixed>|null $speakable The page's SpeakableSpecification, null when off
     */
    private function provider(array $providers, ?array $speakable = null): ArticleSchemaProvider
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $store        = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager->method('getStore')->willReturn($store);
        $speakableSpecification = $this->createStub(SpeakableSpecification::class);
        $speakableSpecification->method('get')->willReturn($speakable);

        return new ArticleSchemaProvider(
            $this->layout,
            $storeManager,
            $this->organisationId,
            new HandleMatcher(),
            $speakableSpecification,
            $providers
        );
    }

    public function testHandlesEveryPage(): void
    {
        $this->assertSame(['*'], $this->provider([])->getHandles());
    }

    public function testEmptyPoolReturnsNoSchemas(): void
    {
        $this->assertSame([], $this->provider([])->getSchemas());
    }

    public function testProviderReturningNullProducesNothing(): void
    {
        $this->assertSame([], $this->provider([$this->makeDataProvider(null)])->getSchemas());
    }

    public function testNonMatchingHandleIsSkipped(): void
    {
        $article = ['headline' => 'X', 'url' => 'https://acme.com/x', 'datePublished' => '2026-01-01'];
        $schemas = $this->provider([$this->makeDataProvider($article, ['cms_page_view'])])->getSchemas();
        $this->assertSame([], $schemas);
    }

    public function testArticleMissingRequiredFieldsIsSkipped(): void
    {
        $schemas = $this->provider([$this->makeDataProvider(['headline' => 'X'])])->getSchemas();
        $this->assertSame([], $schemas);
    }

    public function testBuildsBlogPostingWithOrganisationReferences(): void
    {
        $article = [
            'headline'      => 'The Best Markets',
            'url'           => 'https://acme.com/blog/markets',
            'datePublished' => '2026-05-10',
            'description'   => 'A guide.',
            'dateModified'  => '2026-06-01',
            'image'         => 'https://acme.com/img.jpg',
            'keywords'      => ['markets'],
        ];
        $schemas = $this->provider([$this->makeDataProvider($article)])->getSchemas();

        $this->assertCount(1, $schemas);
        $node = $schemas[0];
        $this->assertSame('BlogPosting', $node['@type']);
        $this->assertSame('https://acme.com/blog/markets#article', $node['@id']);
        $this->assertSame('The Best Markets', $node['headline']);
        $this->assertSame(['@id' => 'https://acme.com/#organization'], $node['author']);
        $this->assertSame(['@id' => 'https://acme.com/#organization'], $node['publisher']);
        $this->assertSame('A guide.', $node['description']);
        $this->assertSame(['markets'], $node['keywords']);
        $this->assertArrayNotHasKey('speakable', $node, 'Speakable is off.');
    }

    public function testTheBlogPostingCarriesTheSpeakableSpecification(): void
    {
        $spec    = ['@type' => 'SpeakableSpecification', 'cssSelector' => ['.post-title']];
        $article = ['headline' => 'The Best Markets', 'url' => 'https://acme.com/blog/markets'];

        $schemas = $this->provider([$this->makeDataProvider($article)], $spec)->getSchemas();

        $this->assertSame($spec, $schemas[0]['speakable'] ?? null);
    }
}
