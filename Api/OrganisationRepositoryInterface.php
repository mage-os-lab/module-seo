<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

use MageOS\Seo\Api\Data\OrganisationInterface;

interface OrganisationRepositoryInterface
{
    /**
     * Load the single Organisation settings record (entity_id = 1).
     *
     * Returns a populated model; creates an empty one if not yet saved.
     *
     * @return \MageOS\Seo\Api\Data\OrganisationInterface
     */
    public function get(): OrganisationInterface;

    /**
     * Persist the Organisation settings record.
     *
     * @param \MageOS\Seo\Api\Data\OrganisationInterface $organisation
     * @return \MageOS\Seo\Api\Data\OrganisationInterface
     */
    public function save(OrganisationInterface $organisation): OrganisationInterface;
}
