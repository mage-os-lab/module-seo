<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData\Provider;

use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\ArticleDataProviderInterface;
use MageOS\Seo\Api\StructuredDataProviderInterface;
use MageOS\Seo\Model\Pool\HandleMatcher;
use MageOS\Seo\Model\StructuredData\OrganisationId;
use MageOS\Seo\Model\StructuredData\SpeakableSpecification;

/**
 * Emits BlogPosting structured data from bridge-supplied article data.
 *
 * Iterates the registered ArticleDataProviderInterface pool (empty by default) and emits a node for
 * the first provider that has an article for the current page. Author and publisher both reference
 * the shared Organisation @id. With Speakable on, the BlogPosting carries the page's
 * SpeakableSpecification — Google's primary use of it.
 */
class ArticleSchemaProvider implements StructuredDataProviderInterface
{
    /**
     * @param LayoutInterface $layout
     * @param StoreManagerInterface $storeManager
     * @param OrganisationId $organisationId
     * @param HandleMatcher $handleMatcher
     * @param SpeakableSpecification $speakable
     * @param array<mixed> $dataProviders
     */
    public function __construct(
        private readonly LayoutInterface        $layout,
        private readonly StoreManagerInterface  $storeManager,
        private readonly OrganisationId         $organisationId,
        private readonly HandleMatcher          $handleMatcher,
        private readonly SpeakableSpecification $speakable,
        private readonly array                  $dataProviders = []
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['*'];
    }

    /**
     * @inheritdoc
     */
    public function getSchemas(): array
    {
        $activeHandles = $this->layout->getUpdate()->getHandles();
        $storeId       = (int) $this->storeManager->getStore()->getId();

        foreach ($this->dataProviders as $provider) {
            if (!$provider instanceof ArticleDataProviderInterface) {
                continue;
            }
            if (!$this->handleMatcher->matches($provider->getHandles(), $activeHandles)) {
                continue;
            }
            $article = $provider->getArticle($storeId);
            if (!empty($article) && !empty($article['headline']) && !empty($article['url'])) {
                return [$this->build($article, $storeId)];
            }
        }

        return [];
    }

    /**
     * Build the BlogPosting node from article data.
     *
     * @param array<string,mixed> $article
     * @param int $storeId
     * @return array<string, mixed>
     */
    private function build(array $article, int $storeId): array
    {
        $orgId = $this->organisationId->getId($storeId);

        $node = [
            '@context'      => 'https://schema.org',
            '@type'         => 'BlogPosting',
            '@id'           => $article['url'] . '#article',
            'headline'      => $article['headline'],
            'url'           => $article['url'],
            'datePublished' => $article['datePublished'] ?? '',
            'author'        => ['@id' => $orgId],
            'publisher'     => ['@id' => $orgId],
        ];

        foreach (['description', 'dateModified', 'image'] as $field) {
            if (!empty($article[$field])) {
                $node[$field] = $article[$field];
            }
        }
        if (!empty($article['keywords'])) {
            $node['keywords'] = $article['keywords'];
        }

        $speakable = $this->speakable->get();
        if ($speakable !== null) {
            $node['speakable'] = $speakable;
        }

        return $node;
    }
}
