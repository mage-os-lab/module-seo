<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Contributes additional JSON-LD lines to the /llms.jsonl catalog feed.
 *
 * Called once after all product lines are generated. A bridge module (e.g. a marketplace) registers
 * an implementation in the pool via its own di.xml to append vendor LocalBusiness lines, etc.
 *
 * @api
 */
interface JsonlLineProviderInterface
{
    /**
     * Return additional JSON-LD nodes to append, each serialised to one NDJSON line.
     *
     * @param int $storeId
     * @return array<int, array<string, mixed>>
     */
    public function getAdditionalLines(int $storeId): array;
}
