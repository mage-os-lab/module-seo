<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sitemap\Model\Sitemap;
use MageOS\Seo\Api\Sitemap\ItemEnricherInterface;
use MageOS\Seo\Api\Sitemap\ItemFilterInterface;
use MageOS\Seo\Api\Sitemap\ItemProviderInterface;
use MageOS\Seo\Api\Sitemap\RowRendererInterface;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Sitemap\ItemProvider\Composite;
use MageOS\Seo\Model\Sitemap\ItemProvider\CoreProviderAdapterFactory;
use Psr\Log\LoggerInterface;

/**
 * Generates a sitemap configured under Marketing → Site Map, in place of core's generator.
 *
 * Reached through Plugin\Sitemap\UseSeoGenerator when the MageOS SEO generator is selected. For
 * each type, in order — pages, categories, products, any other module's own types, then "other" —
 * every provider of that type is streamed a chunk at a time: the enrichers fill the items' data
 * bags, the filters decide what is listed, the renderers write each row, and the Writer files the
 * rows. Nothing holds the whole catalogue, so core's standard and batch generation methods are the
 * same thing here.
 *
 * When the files are in place the core sitemap's time is set and it is saved, as core's own
 * generator finishes — the admin grid, cron and robots.txt see an ordinary sitemap.
 */
class Generator
{
    /**
     * Types written before any other, in this order.
     */
    private const LEADING_TYPES = [
        ItemProviderInterface::TYPE_PAGES,
        ItemProviderInterface::TYPE_CATEGORIES,
        ItemProviderInterface::TYPE_PRODUCTS,
    ];

    /**
     * A type becomes part of a file name.
     */
    private const TYPE_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @param Composite $composite
     * @param CoreProviderAdapterFactory $coreProviderAdapterFactory
     * @param WriterFactory $writerFactory
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param RowRendererInterface[] $renderers In row order
     * @param ItemEnricherInterface[] $enrichers
     * @param ItemFilterInterface[] $filters
     * @param int $chunkSize Items handed to the enrichers at a time
     */
    public function __construct(
        private readonly Composite                  $composite,
        private readonly CoreProviderAdapterFactory $coreProviderAdapterFactory,
        private readonly WriterFactory              $writerFactory,
        private readonly DateTime                   $dateTime,
        private readonly LoggerInterface            $logger,
        private readonly array                      $renderers = [],
        private readonly array                      $enrichers = [],
        private readonly array                      $filters = [],
        private readonly int                        $chunkSize = 1000
    ) {
    }

    /**
     * Write the sitemap's files and save it.
     *
     * @param Sitemap $sitemap
     * @return void
     */
    public function generate(Sitemap $sitemap): void
    {
        $storeId   = (int) $sitemap->getStoreId();
        $providers = $this->providersByType();

        /** @var Writer $writer */
        $writer = $this->writerFactory->create([
            'sitemap'    => $sitemap,
            'urlsetOpen' => $this->urlsetOpen(),
            'types'      => array_values(array_unique(array_merge(
                array_keys($providers),
                self::LEADING_TYPES,
                [ItemProviderInterface::TYPE_OTHER]
            ))),
        ]);

        foreach ($providers as $type => $typeProviders) {
            $writer->startType($type);
            foreach ($typeProviders as $provider) {
                foreach ($this->chunks($provider->iterateItems($storeId)) as $chunk) {
                    $this->writeChunk($writer, $chunk, $storeId);
                }
            }
        }

        $writer->finish();

        $sitemap->setSitemapTime($this->dateTime->gmtDate('Y-m-d H:i:s'));
        $sitemap->save();
    }

    /**
     * Enrich, filter and write one chunk of items.
     *
     * @param Writer $writer
     * @param SitemapItemInterface[] $chunk
     * @param int $storeId
     * @return void
     */
    private function writeChunk(Writer $writer, array $chunk, int $storeId): void
    {
        foreach ($this->enrichers as $enricher) {
            $enricher->enrich($chunk, $storeId);
        }

        foreach ($chunk as $item) {
            if (!$this->isIncluded($item, $storeId)) {
                continue;
            }

            $row = '';
            foreach ($this->renderers as $renderer) {
                $row .= $renderer->render($item, $storeId);
            }
            $writer->writeRow('<url>' . $row . '</url>');
        }
    }

    /**
     * Whether every filter accepts the item.
     *
     * @param SitemapItemInterface $item
     * @param int $storeId
     * @return bool
     */
    private function isIncluded(SitemapItemInterface $item, int $storeId): bool
    {
        foreach ($this->filters as $filter) {
            if (!$filter->isIncluded($item, $storeId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Group items into chunks without reading ahead further than one chunk.
     *
     * @param iterable<SitemapItemInterface> $items
     * @return \Generator<SitemapItemInterface[]>
     */
    private function chunks(iterable $items): \Generator
    {
        $chunk = [];
        foreach ($items as $item) {
            $chunk[] = $item;
            if (\count($chunk) >= max(1, $this->chunkSize)) {
                yield $chunk;
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            yield $chunk;
        }
    }

    /**
     * Every registered provider, grouped by the type of file its items go in, in writing order.
     *
     * A provider written against core's interface alone is adapted and goes in "other"; so does
     * one whose type cannot be part of a file name, with a warning.
     *
     * @return array<string,ItemProviderInterface[]>
     */
    private function providersByType(): array
    {
        $byType = [];
        foreach ($this->composite->getProviders() as $name => $provider) {
            if (!$provider instanceof ItemProviderInterface) {
                $provider = $this->coreProviderAdapterFactory->create(['provider' => $provider]);
            }

            $type = $provider->getType();
            if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
                $this->logger->warning(\sprintf(
                    'MageOS_Seo: sitemap provider "%s" has type "%s", which cannot be part of a file name;'
                    . ' its items are written with "other".',
                    $name,
                    $type
                ));
                $type = ItemProviderInterface::TYPE_OTHER;
            }

            $byType[$type][] = $provider;
        }

        $ordered = [];
        foreach (self::LEADING_TYPES as $type) {
            if (isset($byType[$type])) {
                $ordered[$type] = $byType[$type];
                unset($byType[$type]);
            }
        }

        $other = $byType[ItemProviderInterface::TYPE_OTHER] ?? null;
        unset($byType[ItemProviderInterface::TYPE_OTHER]);
        ksort($byType);

        $ordered += $byType;
        if ($other !== null) {
            $ordered[ItemProviderInterface::TYPE_OTHER] = $other;
        }

        return $ordered;
    }

    /**
     * The opening of every child file: the declaration and `<urlset>` with the renderers' namespaces.
     *
     * @return string
     */
    private function urlsetOpen(): string
    {
        $namespaces = [];
        foreach ($this->renderers as $renderer) {
            $namespaces += $renderer->getNamespaces();
        }

        $attributes = '';
        foreach ($namespaces as $prefix => $uri) {
            $attributes .= ' xmlns:' . $prefix . '="' . $uri . '"';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . $attributes . '>' . PHP_EOL;
    }
}
