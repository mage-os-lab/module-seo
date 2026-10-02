<?php

declare(strict_types=1);

namespace MageOS\Seo\Block\Adminhtml\System\Config\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * A system configuration field shown as a calendar date, YYYY-MM-DD.
 *
 * Core's system configuration has no date field. A `type="date"` field gets the framework's date
 * element, which needs a format before it can render; this gives it the ISO date, the same in every
 * admin locale, and no time.
 */
class Date extends Field
{
    /**
     * @inheritdoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $element->setData('date_format', 'yyyy-MM-dd');
        $element->setData('time_format', null);

        return parent::_getElementHtml($element);
    }
}
