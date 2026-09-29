<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData\Provider;

use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\EventDataProviderInterface;
use MageOS\Seo\Api\StructuredDataProviderInterface;
use MageOS\Seo\Model\Pool\HandleMatcher;
use MageOS\Seo\Model\StructuredData\OrganizationId;

/**
 * Emits Event structured data from bridge-supplied event data.
 *
 * Collects events from every matching EventDataProviderInterface in the pool (empty by default) and
 * emits one Event node per event, each linking the store Organization as organizer.
 */
class EventSchemaProvider implements StructuredDataProviderInterface
{
    /**
     * @param LayoutInterface $layout
     * @param StoreManagerInterface $storeManager
     * @param OrganizationId $organizationId
     * @param HandleMatcher $handleMatcher
     * @param array<mixed> $dataProviders
     */
    public function __construct(
        private readonly LayoutInterface                $layout,
        private readonly StoreManagerInterface $storeManager,
        private readonly OrganizationId        $organizationId,
        private readonly HandleMatcher         $handleMatcher,
        private readonly array                 $dataProviders = []
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
        $orgId         = $this->organizationId->getId($storeId);

        $schemas = [];
        foreach ($this->dataProviders as $provider) {
            if (!$provider instanceof EventDataProviderInterface) {
                continue;
            }
            if (!$this->handleMatcher->matches($provider->getHandles(), $activeHandles)) {
                continue;
            }
            foreach ($provider->getEvents($storeId) as $event) {
                if (!empty($event['name']) && !empty($event['startDate'])) {
                    $schemas[] = $this->build($event, $orgId);
                }
            }
        }

        return $schemas;
    }

    /**
     * Build an Event node from event data, adding context and organizer reference.
     *
     * @param array<string,mixed> $event
     * @param string $orgId
     * @return array<string, mixed>
     */
    private function build(array $event, string $orgId): array
    {
        return array_merge(
            [
                '@context'  => 'https://schema.org',
                '@type'     => 'Event',
                'organizer' => ['@id' => $orgId],
            ],
            $event
        );
    }
}
