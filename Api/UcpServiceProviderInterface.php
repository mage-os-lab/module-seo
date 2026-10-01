<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Contract for one transport binding of a UCP service declared in the /.well-known/ucp profile.
 *
 * A module that serves a UCP service registers a provider per transport binding in its own di.xml
 * (the `providers` argument of MageOS\Seo\Model\Ucp\ServicePool). Bindings sharing a service key
 * are listed together under it, as UCP 2026-08-25 requires.
 *
 * @api
 */
interface UcpServiceProviderInterface
{
    /**
     * The service's reverse-domain name (e.g. "dev.ucp.shopping").
     *
     * @return string
     */
    public function getServiceKey(): string;

    /**
     * Whether this binding is currently declared.
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * One binding entry, as the business schema defines it.
     *
     * `version` (YYYY-MM-DD) and `transport` (rest, mcp, a2a or embedded) are required, and so is
     * an absolute `endpoint` URL for rest, mcp and a2a. `spec`, `schema`, `id` and `config` are
     * optional; a `schema` URL must be published under the service's own namespace. An entry that
     * breaks these rules is logged and left out of the profile.
     *
     * @return array<string, mixed>
     */
    public function getServiceData(): array;
}
