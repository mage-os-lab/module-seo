<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Router;

/**
 * The paths served directly as public documents, as the modules that serve them register them.
 *
 * No session is started for these requests: a session cookie and PHP's no-cache headers on a
 * response meant to be shared-cached would undo the caching. A module adds its paths from its own
 * di.xml:
 *
 *     <type name="MageOS\Seo\Api\Router\PublicPathsInterface">
 *         <arguments>
 *             <argument name="paths" xsi:type="array">
 *                 <item name="my_document" xsi:type="string">my-document.txt</item>
 *             </argument>
 *             <argument name="prefixes" xsi:type="array">
 *                 <item name="my_documents" xsi:type="string">my-documents/</item>
 *             </argument>
 *         </arguments>
 *     </type>
 *
 * `paths` are exact request paths; `prefixes` end in `/`, and every path under one is public.
 *
 * @api
 */
interface PublicPathsInterface
{
    /**
     * Whether a request path is one a module registered as public.
     *
     * @param string $pathInfo Raw path info, with or without surrounding slashes
     * @return bool
     */
    public function isPublicPath(string $pathInfo): bool;
}
