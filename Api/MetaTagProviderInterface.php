<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Contributes meta tags (Open Graph, X, description) to the page's <head> for the layout handles it
 * names. Register an implementation in the meta tag compositor's pool via your own di.xml.
 *
 * @api
 */
interface MetaTagProviderInterface
{
    /**
     * Return meta tag definitions to output in <head>.
     *
     * Each entry is an associative array with keys matching HTML meta attributes:
     * 'name', 'property', 'content'.
     *
     * Example:
     * [
     *   ['property' => 'og:title',    'content' => 'Page Title'],
     *   ['name'     => 'description', 'content' => 'Page desc'],
     * ]
     *
     * @return mixed[]
     */
    public function getMetaTags(): array;

    /**
     * Layout handles this provider applies to.
     *
     * Return ['*'] to run on every page.
     *
     * @return string[]
     */
    public function getHandles(): array;
}
