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
use MageOS\Seo\Model\Organization\LogoFileValidator;
use MageOS\Seo\Model\Organization\LogoUrl;

class UploadLogo extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Seo::organization';

    /**
     * The extension of the file being uploaded, for the validate callback, which is given only the
     * temporary file.
     *
     * @var string
     */
    private string $extension = '';

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param UploaderFactory $uploaderFactory
     * @param Filesystem $filesystem
     * @param StoreManagerInterface $storeManager
     * @param LogoFileValidator $logoFileValidator
     */
    public function __construct(
        Context                                     $context,
        private readonly JsonFactory                $jsonFactory,
        private readonly UploaderFactory            $uploaderFactory,
        private readonly Filesystem                 $filesystem,
        private readonly StoreManagerInterface      $storeManager,
        private readonly LogoFileValidator          $logoFileValidator
    ) {
        parent::__construct($context);
    }

    /**
     * Handle logo file upload from the fileUploader UI component.
     *
     * Returns a JSON response in the format the uploader expects. Its `file` is the path relative
     * to the media directory, which the form posts back and Save stores: the logo is published
     * under each store view's media URL. Its `url` is only the admin's preview.
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
            $uploader->setAllowedExtensions(array_keys(LogoFileValidator::TYPES));
            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(false);
            $uploader->setAllowCreateFolders(true);
            // The content must be the image the extension says, and must decode (issue #20).
            $this->extension = (string) $uploader->getFileExtension();
            $uploader->addValidateCallback('seo_logo_image_content', $this, 'validateImageContent');

            $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $uploadResult   = $uploader->save($mediaDirectory->getAbsolutePath(LogoUrl::UPLOAD_DIR));

            if (!$uploadResult) {
                throw new LocalizedException(__('File upload failed.'));
            }

            $path     = LogoUrl::UPLOAD_DIR . '/' . $uploadResult['file'];
            $mediaUrl = rtrim(
                (string) $this->storeManager->getStore()->getBaseUrl(
                    \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
                ),
                '/'
            );

            return $result->setData([
                'name'        => $uploadResult['name'],
                'file'        => $path,
                'url'         => $mediaUrl . '/' . $path,
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
     * Uploader validate callback: refuse a file that is not the image its extension says.
     *
     * @param string $filePath
     * @throws LocalizedException
     * @return void
     */
    public function validateImageContent(string $filePath): void
    {
        $this->logoFileValidator->validate($filePath, $this->extension);
    }
}
