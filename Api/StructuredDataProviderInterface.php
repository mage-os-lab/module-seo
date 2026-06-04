<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

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
