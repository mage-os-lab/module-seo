<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer\Adminhtml;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Model\Page;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Model\Cms\HreflangGroup;
use MageOS\Seo\Model\Cms\TranslationGroupCache;
use Psr\Log\LoggerInterface;

/**
 * Persists the CMS page "Search Engine Optimization" fieldset.
 *
 * Registered in the admin area only, like the product and category equivalents: the observer acts
 * on admin form data, and a global registration would let a REST, GraphQL or import save steer it
 * with a request payload.
 *
 * The values are written to the page's global (store 0) row, which is the row
 * Plugin\Cms\Page\AddSeoConfigToFormData loads. The CMS page form has no store switcher, only the
 * page's store assignment, so the admin has no store view to save "for"; and a page assigned to
 * one store view is only served there, so its global row already applies exactly where the page
 * does. Store-view rows remain supported for reading, for anything writing through
 * Model\Cms\ConfigRepository directly.
 */
class SaveCmsPageSeoConfig implements ObserverInterface
{
    /**
     * The form fields, named so they cannot collide with a core or third-party cms_page column.
     */
    public const FIELD_ROBOTS_META    = 'mageos_seo_robots_meta';
    public const FIELD_HREFLANG_GROUP = 'mageos_seo_hreflang_group';

    /**
     * @param ConfigRepository $configRepository
     * @param HreflangGroup $hreflangGroup
     * @param TranslationGroupCache $translationGroupCache
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ConfigRepository      $configRepository,
        private readonly HreflangGroup         $hreflangGroup,
        private readonly TranslationGroupCache $translationGroupCache,
        private readonly ManagerInterface      $messageManager,
        private readonly LoggerInterface       $logger
    ) {
    }

    /**
     * Save the page's robots override and translation group.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /* @var Magento\Cms\Model\Page $page */
        $page = $observer->getEvent()->getData('object') ?? $observer->getEvent()->getData('page');
        if (!$page instanceof PageInterface ||
            !$page instanceof Page
        ) {
            return;
        }

        $pageId = (int) $page->getId();
        if ($pageId <= 0) {
            return;
        }

        // A save that never carried a field — a REST call, an import — leaves its stored value
        // alone rather than blanking it.
        $data = [];

        $robotsMeta = $page->getData(self::FIELD_ROBOTS_META);
        if ($robotsMeta !== null) {
            $data['robots_meta'] = (string) $robotsMeta ?: null;
        }

        $groupEntered = $page->getData(self::FIELD_HREFLANG_GROUP);
        if ($groupEntered !== null) {
            $group = $this->hreflangGroup->normalise((string) $groupEntered);
            if ($group === null || $this->hreflangGroup->isValid($group)) {
                $data['hreflang_group'] = $group;
            } else {
                $this->messageManager->addWarningMessage((string) __(
                    'The page was saved, but its hreflang translation group was not: use letters, '
                    . 'digits, dots, hyphens and underscores only, for example "about-us".'
                ));
            }
        }

        if ($data === []) {
            return;
        }

        try {
            $previousGroup = $this->configRepository->getHreflangGroup($pageId);
            $this->configRepository->save($pageId, $data);
        } catch (\Throwable $e) {
            // The page itself is already saved; a failure in the SEO table must not make the
            // whole save look failed.
            $this->logger->error(
                'MageOS_Seo: could not save CMS page SEO config: ' . $e->getMessage(),
                ['exception' => $e, 'page_id' => $pageId]
            );
            $this->messageManager->addWarningMessage(
                (string) __('The page was saved, but its SEO settings could not be saved.')
            );

            return;
        }

        if (\array_key_exists('hreflang_group', $data) && $data['hreflang_group'] !== $previousGroup) {
            $this->groupChanged($pageId, $previousGroup, $data['hreflang_group']);
        }
    }

    /**
     * Bring the cached pages of both groups up to date after a page joined or left one.
     *
     * Every page of a group lists the others in its head. Nothing about the other pages changed,
     * so the save's own cache tags do not reach them. (The sitemap needs nothing here: saving the
     * group is a change to this module's CMS page settings, which queues the pages' rebuild — see
     * Feed\InvalidationPolicy.)
     *
     * @param int $pageId
     * @param string|null $previousGroup
     * @param string|null $group
     * @return void
     */
    private function groupChanged(int $pageId, ?string $previousGroup, ?string $group): void
    {
        try {
            $this->translationGroupCache->purge([$previousGroup, $group]);
        } catch (\Throwable $e) {
            // The settings are saved; what failed is only bringing other pages up to date, which
            // their cache lifetime and the nightly rebuild will still do.
            $this->logger->error(
                'MageOS_Seo: could not refresh the translation group of CMS page ' . $pageId
                . ': ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
