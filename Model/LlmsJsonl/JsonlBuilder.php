<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\LlmsJsonl;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\InventorySalesApi\Api\AreProductsSalableInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\JsonlLineProviderInterface;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use Psr\Log\LoggerInterface;

/**
 * Builds the /llms.jsonl document: one JSON-LD Product node per line for the store's catalog, plus
 * any lines contributed by bridge JsonlLineProviderInterface implementations.
 *
 * The catalog is processed in pages with URL rewrites and MSI salability resolved in one batch call
 * per page, so large catalogs neither exhaust memory nor trigger per-product lookup queries.
 */
class JsonlBuilder
{
    private const PAGE_SIZE = 1000;

    /**
     * @param CollectionFactory $collectionFactory
     * @param ProductLineBuilder $productLineBuilder
     * @param StoreManagerInterface $storeManager
     * @param AreProductsSalableInterface $areProductsSalable
     * @param AvailabilityResolver $availabilityResolver
     * @param LoggerInterface $logger
     * @param array<mixed> $lineProviders
     */
    public function __construct(
        private readonly CollectionFactory           $collectionFactory,
        private readonly ProductLineBuilder          $productLineBuilder,
        private readonly StoreManagerInterface       $storeManager,
        private readonly AreProductsSalableInterface $areProductsSalable,
        private readonly AvailabilityResolver        $availabilityResolver,
        private readonly LoggerInterface             $logger,
        private readonly array                       $lineProviders = []
    ) {
    }

    /**
     * Stream the NDJSON document for the current store, one line at a time.
     *
     * The document is never assembled in memory: at 100k SKUs it runs to tens of megabytes.
     *
     * @return \Generator<string> Lines, each ending in a newline
     */
    public function stream(): \Generator
    {
        $storeId = (int) $this->storeManager->getStore()->getId();

        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addStoreFilter($storeId);
        $collection->addAttributeToSelect(['name', 'short_description', 'description', 'sku', 'image']);
        $collection->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED]);
        $collection->addAttributeToFilter('visibility', [
            'in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH],
        ]);
        $collection->addFinalPrice();
        // Loads request paths with the collection so getProductUrl() never falls back
        // to a per-product url_rewrite lookup.
        $collection->addUrlRewrite();
        $collection->setPageSize(self::PAGE_SIZE);

        $lastPage = $collection->getLastPageNumber();

        for ($page = 1; $page <= $lastPage; $page++) {
            $collection->setCurPage($page);
            $collection->clear();

            // One MSI batch salability call per page instead of per-product
            // resolution inside the line builder.
            $skus = [];
            foreach ($collection as $product) {
                if ($product instanceof ProductInterface && (string) $product->getSku() !== '') {
                    $skus[] = (string) $product->getSku();
                }
            }
            $salability = $this->resolveSalability($skus);

            foreach ($collection as $product) {
                if (!$product instanceof ProductInterface) {
                    continue;
                }
                $line = $this->encode($this->productLineBuilder->build(
                    $product,
                    $salability[(string) $product->getSku()] ?? false
                ));
                if ($line !== '') {
                    yield $line . "\n";
                }
            }
        }

        foreach ($this->lineProviders as $provider) {
            if (!$provider instanceof JsonlLineProviderInterface) {
                continue;
            }
            foreach ($provider->getAdditionalLines($storeId) as $node) {
                $line = $this->encode($node);
                if ($line !== '') {
                    yield $line . "\n";
                }
            }
        }
    }

    /**
     * Resolve salability for a page of SKUs in one MSI batch call.
     *
     * On inventory API failure every product in the page is reported not
     * salable (matching AvailabilityResolver's OutOfStock default) and the
     * failure is logged rather than aborting the whole feed build.
     *
     * @param string[] $skus
     * @return array<string, bool> sku => salable
     */
    private function resolveSalability(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }

        $salability = [];
        try {
            $stockId = $this->availabilityResolver->getCurrentStockId();
            foreach ($this->areProductsSalable->execute($skus, $stockId) as $result) {
                $salability[(string) $result->getSku()] = $result->isSalable();
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'MageOS_Seo: llms.jsonl salability batch failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }

        return $salability;
    }

    /**
     * Encode one node to a compact JSON line, or empty string on failure.
     *
     * @param array<string,mixed> $node
     * @return string
     */
    private function encode(array $node): string
    {
        $json = json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? '' : $json;
    }
}
