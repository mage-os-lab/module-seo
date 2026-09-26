<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Setup\Patch\Data;

use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Setup\Patch\Data\RenameOrganisationTypes;
use PHPUnit\Framework\TestCase;

/**
 * The two organisation types that were never schema.org types are renamed in stored rows, and
 * reverting the patch puts the old names back.
 *
 * @magentoAppArea global
 * @magentoDbIsolation enabled
 */
class RenameOrganisationTypesTest extends TestCase
{
    /**
     * @return void
     */
    public function testTheInvalidTypesAreRenamedAndRevertRestoresThem(): void
    {
        $this->storeOrganisation('default', 0, 'EducationalOrg');
        $this->storeOrganisation('websites', 1, 'GovernmentOrg');
        $this->storeOrganisation('stores', 1, 'Corporation');

        $patch = Bootstrap::getObjectManager()->create(RenameOrganisationTypes::class);
        $patch->apply();

        $this->assertSame('EducationalOrganization', $this->orgType('default', 0));
        $this->assertSame('GovernmentOrganization', $this->orgType('websites', 1));
        $this->assertSame('Corporation', $this->orgType('stores', 1), 'Other types are left as they are.');

        $patch->revert();

        $this->assertSame('EducationalOrg', $this->orgType('default', 0));
        $this->assertSame('GovernmentOrg', $this->orgType('websites', 1));
        $this->assertSame('Corporation', $this->orgType('stores', 1));
    }

    /**
     * Store an organisation of the given type for a scope. setOrgType() takes any string, which is
     * how the old values came to be stored.
     *
     * @param string $scope
     * @param int $scopeId
     * @param string $orgType
     * @return void
     */
    private function storeOrganisation(string $scope, int $scopeId, string $orgType): void
    {
        $repository   = $this->repository();
        $organisation = $repository->get($scope, $scopeId);
        $organisation->setName('MageOS SEO ' . $scope);
        $organisation->setOrgType($orgType);
        $repository->save($organisation);
    }

    /**
     * The stored type, read by a fresh repository so nothing kept from before the patch answers.
     *
     * @param string $scope
     * @param int $scopeId
     * @return string
     */
    private function orgType(string $scope, int $scopeId): string
    {
        return $this->repository()->get($scope, $scopeId)->getOrgType();
    }

    /**
     * @return OrganisationRepositoryInterface
     */
    private function repository(): OrganisationRepositoryInterface
    {
        return Bootstrap::getObjectManager()->create(OrganisationRepositoryInterface::class);
    }
}
