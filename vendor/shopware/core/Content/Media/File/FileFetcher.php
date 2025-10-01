<?php declare(strict_types=1);

namespace Shopware\Core\Content\Media\File;

use Shopware\Core\Content\Media\MediaException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Util\Hasher;
use Symfony\Component\HttpFoundation\Request;

#[Package('discovery')]
class FileFetcher
{
    /**
     * @internal
     */
    public function __construct(
        private readonly FileUrlValidatorInterface $fileUrlValidator,
        private readonly FileService $fileService,
        private readonly bool $enableUrlUploadFeature = true,
        private readonly bool $enableUrlValidation = true,
        private readonly int $maxFileSize = 0
    ) {
    }

    public function fetchRequestData(Request $request, string $fileName): MediaFile
    {
        $extension = $this->getExtensionFromRequest($request);
        $expectedLength = (int) $request->headers->get('content-length');

        $inputStream = $request->getContent(true);
        $destStream = $this->openDestinationStream($fileName);

        try {
            $bytesWritten = $this->copyStreams($inputStream, $destStream, 0);
        } finally {
            fclose($inputStream);
            fclose($destStream);
        }

        if ($expectedLength !== $bytesWritten) {
            throw MediaException::invalidContentLength();
        }

        return new MediaFile(
            $fileName,
            FileInfoHelper::getMimeType($fileName, $extension),
            $extension,
            $bytesWritten,
            // Change length of db field `media`.`file_hash` if algorithm is changed
            Hasher::hashFile($fileName, 'md5')
        );
    }

    public function fetchFromURL(string $url, string $fileName, ?string $fileExtension = null): MediaFile
    {
        if (!$this->enableUrlUploadFeature) {
            throw MediaException::disableUrlUploadFeature();
        }

        if (!$this->fileService->isUrl($url)) {
            throw MediaException::invalidUrl($url);
        }

        if ($this->enableUrlValidation && !$this->fileUrlValidator->isValid($url)) {
            throw MediaException::illegalUrl($url);
        }

        $inputStream = $this->openSourceFromUrl($url);
        $destStream = $this->openDestinationStream($fileName);

        try {
            $writtenBytes = $this->copyStreams($inputStream, $destStream, $this->maxFileSize);
        } finally {
            fclose($inputStream);
            fclose($destStream);
        }

        $mimeType = FileInfoHelper::getMimeType($fileName, $fileExtension);
        $fileExtension = $fileExtension ?: FileInfoHelper::getExtension($mimeType);

        return new MediaFile(
            $fileName,
            $mimeType,
            $fileExtension,
            $writtenBytes,
            // Change length of db field `media`.`file_hash` if algorithm is changed
            Hasher::hashFile($fileName, 'md5')
        );
    }

    public function fetchFileFromURL(Request $request, string $fileName): MediaFile
    {
        $url = $this->getUrlFromRequest($request);

        return $this->fetchFromURL($url, $fileName, (string) $request->query->get('extension'));
    }

    public function fetchBlob(string $blob, string $extension, string $contentType): MediaFile
    {
        $tempFile = (string) tempnam(sys_get_temp_dir(), '');
        $fh = @fopen($tempFile, 'w');
        \assert($fh !== false);

        $blobSize = (int) @fwrite($fh, $blob);
        $fileHash = $tempFile ? Hasher::hashFile($tempFile, 'md5') : null;

        return new MediaFile(
            $tempFile,
            $contentType,
            $extension,
            $blobSize,
            $fileHash
        );
    }

    public function cleanUpTempFile(MediaFile $mediaFile): void
    {
        if ($mediaFile->getFileName() !== '') {
            unlink($mediaFile->getFileName());
        }
    }

    /**
     * @throws MediaException
     */
    private function getExtensionFromRequest(Request $request): string
    {
        $extension = (string) $request->query->get('extension');
        if ($extension === '') {
            throw MediaException::missingFileExtension();
        }

        return $extension;
    }

    /**
     * @throws MediaException
     */
    private function getUrlFromRequest(Request $request): string
    {
        $url = (string) $request->request->get('url');

        if ($url === '') {
            throw MediaException::missingUrlParameter();
        }

        return $url;
    }

    /**
     * @throws MediaException
     *
     * @return resource
     */
    private function openSourceFromUrl(string $url)
    {
        $streamContext = stream_context_create([
            'http' => [
                'follow_location' => 0,
                'max_redirects' => 0,
                'user_agent' => 'Shopware Remote File Fetcher',
            ],
        ]);

        try {
            $inputStream = @fopen($url, 'r', false, $streamContext);
        } catch (\Throwable) {
            throw MediaException::cannotOpenSourceStreamToRead($url);
        }

        if ($inputStream === false) {
            throw MediaException::cannotOpenSourceStreamToRead($url);
        }

        return $inputStream;
    }

    /**
     * @throws MediaException
     *
     * @return resource
     */
    private function openDestinationStream(string $filename)
    {
        try {
            $inputStream = @fopen($filename, 'w');
        } catch (\Throwable) {
            throw MediaException::cannotOpenSourceStreamToWrite($filename);
        }

        if ($inputStream === false) {
            throw MediaException::cannotOpenSourceStreamToWrite($filename);
        }

        return $inputStream;
    }

    /**
     * @param resource $sourceStream
     * @param resource $destStream
     */
    private function copyStreams($sourceStream, $destStream, int $maxFileSize = 0): int
    {
        if ($maxFileSize === 0) {
            $writtenBytes = stream_copy_to_stream($sourceStream, $destStream);
            if ($writtenBytes === false) {
                throw MediaException::cannotCopyMedia();
            }

            return $writtenBytes;
        }

        $writtenBytes = stream_copy_to_stream($sourceStream, $destStream, $maxFileSize, 0);
        if ($writtenBytes === false) {
            throw MediaException::cannotCopyMedia();
        }

        if ($writtenBytes === $maxFileSize) {
            throw MediaException::fileSizeLimitExceeded();
        }

        return $writtenBytes;
    }
}
