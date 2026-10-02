<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Contract for a UCP capability declared in the /.well-known/ucp profile.
 *
 * A module that implements a UCP capability registers a provider in its own di.xml (the
 * `providers` argument of MageOS\Seo\Model\Ucp\CapabilityPool), without editing MageOS_Seo.
 * Providers sharing a capability key (one per version, say) are listed together under it, as UCP
 * 2026-08-25 requires.
 *
 * @api
 */
interface UcpCapabilityProviderInterface
{
    /**
     * The UCP capability's reverse-domain name (e.g. "dev.ucp.shopping.checkout").
     *
     * @return string
     */
    public function getCapabilityKey(): string;

    /**
     * Whether this capability is currently declared.
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * One capability entry, as the business schema defines it.
     *
     * `version` (YYYY-MM-DD) and an absolute `schema` URL are required, and the schema must be
     * published under the capability's own namespace (dev.ucp.* on ucp.dev). `spec`, `id`, `config`
     * and `extends` are optional. An entry that breaks these rules is logged and left out of the
     * profile.
     *
     * @return array<string, mixed>
     */
    public function getCapabilityData(): array;
}
