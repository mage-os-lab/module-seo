<?php

declare(strict_types=1);

namespace MageOS\Seo\Controller\Adminhtml\Organization;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Magento\Store\Model\StoreManagerInterface;

class UploadLogo extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Seo::organization';

    private const UPLOAD_PATH = 'mage-os/seo/logo';

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param UploaderFactory $uploaderFactory
     * @param Filesystem $filesystem
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context                              $context,
        private readonly JsonFactory         $jsonFactory,
        private readonly UploaderFactory     $uploaderFactory,
        private readonly Filesystem          $filesystem,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    /**
     * Handle logo file upload from the fileUploader UI component.
     *
     * Returns a JSON response in the format the uploader expects.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute(): \Magento\Framework\Controller\Result\Json
    {
        $result = $this->jsonFactory->create();

        try {
            $uploader = $this->uploaderFactory->create(['fileId' => 'logo_upload']);
            // SVG is deliberately excluded: it can carry scripts and the uploader does no
            // content sanitisation, so a stored SVG would be an XSS file on the media host.
            $uploader->setAllowedExtensions(['jpg', 'jpeg', 'gif', 'png']);
            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(false);
            $uploader->setAllowCreateFolders(true);
            // Content validation: reject files whose bytes are not a real image, whatever
            // the extension claims (getimagesize fails on non-image payloads).
            $uploader->addValidateCallback(
                'seo_logo_image_content',
                $this,
                'validateImageContent'
            );

            $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $uploadDir      = $mediaDirectory->getAbsolutePath(self::UPLOAD_PATH);

            $uploadResult = $uploader->save($uploadDir);

            if (!$uploadResult) {
                throw new LocalizedException(__('File upload failed.'));
            }

            $mediaUrl = rtrim(
                (string) $this->storeManager->getStore()->getBaseUrl(
                    \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
                ),
                '/'
            );

            $fileUrl = $mediaUrl . '/' . self::UPLOAD_PATH . '/' . $uploadResult['file'];

            return $result->setData([
                'name'        => $uploadResult['name'],
                'file'        => $uploadResult['file'],
                'url'         => $fileUrl,
                'size'        => $uploadResult['size'],
                'type'        => $uploadResult['type'] ?? 'image',
                'previewType' => 'image',
                'error'       => 0,
            ]);
        } catch (LocalizedException $e) {
            return $result->setData(['error' => $e->getMessage(), 'errorcode' => $e->getCode()]);
        } catch (\Exception $e) {
            return $result->setData(['error' => __('Upload failed. Please try again.'), 'errorcode' => 0]);
        }
    }

    /**
     * Uploader validate callback: reject uploads whose content is not a real raster image.
     *
     * @param string $filePath
     * @throws LocalizedException
     * @return void
     */
    public function validateImageContent(string $filePath): void
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction,Generic.PHP.NoSilencedErrors.Discouraged -- content sniffing requires getimagesize
        $imageInfo = @getimagesize($filePath);
        if ($imageInfo === false) {
            throw new LocalizedException(__('The uploaded file is not a valid image.'));
        }
    }
}
