<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Contract for a document served under /.well-known/.
 *
 * Implementations are registered in the well-known endpoint pool (etc/di.xml) keyed by their
 * path segment. A separate module can serve a new /.well-known/* document by adding its own
 * implementation to the pool via its di.xml — MageOS_Seo never needs editing.
 *
 * @api
 */
interface WellKnownEndpointInterface
{
    /**
     * The path segment after /.well-known/ this endpoint answers (e.g. "ucp", "security.txt").
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Whether this endpoint is currently enabled; disabled endpoints yield a 404.
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * The full Content-Type header value for the response.
     *
     * @return string
     */
    public function getContentType(): string;

    /**
     * The Cache-Control header value for the response.
     *
     * @return string
     */
    public function getCacheControl(): string;

    /**
     * Render the response body.
     *
     * @return string
     */
    public function render(): string;
}
