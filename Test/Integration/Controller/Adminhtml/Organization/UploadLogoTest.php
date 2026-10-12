<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller\Adminhtml\Organization;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * The logo upload route (issue #20): a file that is not the image its name says is refused, and an
 * image is stored, its media-relative path returned for the form to post back (issue #37).
 *
 * The upload is staged as core's own upload tests stage one: a file in the system temp directory,
 * named in $_FILES.
 *
 * @magentoAppArea adminhtml
 */
class UploadLogoTest extends AbstractBackendController
{
    /**
     * @var string|null
     */
    protected $resource = 'MageOS_Seo::organization';

    /**
     * @var string|null
     */
    protected $uri = 'backend/mageos_seo/organization/uploadLogo';

    /**
     * @var string|null
     */
    protected $httpMethod = HttpRequest::METHOD_POST;

    /**
     * Media files the running test caused to be written.
     *
     * @var string[]|null
     */
    private ?array $stored = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $_FILES = [];
        $media  = $this->_objectManager->get(Filesystem::class)->getDirectoryWrite(DirectoryList::MEDIA);
        foreach ($this->stored as $file) {
            if ($media->isExist($file)) {
                $media->delete($file);
            }
        }
        $this->stored = [];
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testAFlashMovieNamedAsAJpegIsRefused(): void
    {
        // A 550 × 400 movie header, padded to the 32 bytes PHP reads after it.
        $body  = str_pad("\x78\x00\x05\x5F\x00\x00\x0F\xA0\x00" . "\x00\x18\x01\x00", 32, "\x00");
        $movie = 'FWS' . \chr(10) . pack('V', 8 + \strlen($body)) . $body;
        $this->assertSame(IMAGETYPE_SWF, getimagesizefromstring($movie)[2] ?? null, 'It must read as a Flash movie.');
        $this->stage('brand.jpg', $movie);

        $response = $this->upload();

        $this->assertSame('The uploaded file is not a JPEG image.', $response['error'] ?? null);
        $this->assertFileDoesNotExist($this->mediaPath('mage-os/seo/logo/brand.jpg'));
    }

    /**
     * @return void
     */
    public function testAnImageIsStoredAndItsMediaPathReturned(): void
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($image);
        $this->stage('mageos-seo-upload-test.png', (string) ob_get_clean());

        $response = $this->upload();

        $this->assertSame(0, $response['error'] ?? null, (string) json_encode($response));
        $this->stored[] = (string) $response['file'];
        $this->assertMatchesRegularExpression(
            '#^mage-os/seo/logo/mageos-seo-upload-test(_\d+)?\.png$#',
            (string) $response['file']
        );
        $this->assertFileExists($this->mediaPath((string) $response['file']));
        $this->assertStringEndsWith('/' . $response['file'], (string) $response['url']);
    }

    /**
     * Put a file in the system temp directory and name it in $_FILES, as a browser upload would.
     *
     * @param string $name
     * @param string $bytes
     * @return void
     */
    private function stage(string $name, string $bytes): void
    {
        $tmp  = $this->_objectManager->get(Filesystem::class)->getDirectoryWrite(DirectoryList::SYS_TMP);
        $path = $tmp->getAbsolutePath(uniqid('mageos-seo-', true));
        file_put_contents($path, $bytes);

        $_FILES['logo_upload'] = [
            'name'     => $name,
            'type'     => 'application/octet-stream',
            'tmp_name' => $path,
            'error'    => 0,
            'size'     => \strlen($bytes),
        ];
    }

    /**
     * Post the upload and decode the JSON answer.
     *
     * @return array<string, mixed>
     */
    private function upload(): array
    {
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->dispatch($this->uri);

        return (array) $this->_objectManager->get(Json::class)->unserialize($this->getResponse()->getBody());
    }

    /**
     * @param string $path
     * @return string
     */
    private function mediaPath(string $path): string
    {
        return $this->_objectManager->get(Filesystem::class)->getDirectoryRead(DirectoryList::MEDIA)
            ->getAbsolutePath($path);
    }
}
