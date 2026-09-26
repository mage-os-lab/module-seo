<?php

declare(strict_types=1);

namespace MageOS\Seo\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use MageOS\Seo\Model\ResourceModel\Organisation as OrganisationResource;

/**
 * Renames the two organisation types that were never schema.org types.
 *
 * The admin offered `EducationalOrg` and `GovernmentOrg`, and each stored type is emitted as the
 * Organization node's @type, so a store that picked either published an invalid type. schema.org's
 * names are `EducationalOrganization` and `GovernmentOrganization`, which the admin now offers.
 *
 * Revertible: revert() renames them back — every row of those types, including any saved after the
 * patch, which is the vocabulary the code before it offers.
 */
class RenameOrganisationTypes implements DataPatchInterface, PatchRevertableInterface
{
    /**
     * Old stored value => schema.org type name.
     */
    private const RENAMES = [
        'EducationalOrg' => 'EducationalOrganization',
        'GovernmentOrg'  => 'GovernmentOrganization',
    ];

    /**
     * @param OrganisationResource $organisationResource
     */
    public function __construct(
        private readonly OrganisationResource $organisationResource
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        foreach (self::RENAMES as $from => $to) {
            $this->organisationResource->renameOrgType($from, $to);
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function revert(): void
    {
        foreach (self::RENAMES as $from => $to) {
            $this->organisationResource->renameOrgType($to, $from);
        }
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
