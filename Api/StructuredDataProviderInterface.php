<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Contributes schema.org nodes to the page's JSON-LD for the layout handles it names. Register an
 * implementation in the structured data compositor's pool via your own di.xml.
 *
 * @api
 */
interface StructuredDataProviderInterface
{
    /**
     * Return one or more schema.org nodes for the current page.
     *
     * Return an empty array to contribute nothing.
     *
     * @return mixed[]
     */
    public function getSchemas(): array;

    /**
     * Layout handles this provider applies to.
     *
     * Return ['*'] to run on every page.
     *
     * @return string[]
     */
    public function getHandles(): array;
}
