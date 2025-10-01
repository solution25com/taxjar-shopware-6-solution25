<?php declare(strict_types=1);

namespace Shopware\Core\Content\ImportExport\Processing\Writer;

use League\Flysystem\FilesystemOperator;
use Shopware\Core\Content\ImportExport\ImportExportException;
use Shopware\Core\Content\ImportExport\Struct\Config;
use Shopware\Core\Framework\Log\Package;

#[Package('fundamentals@after-sales')]
abstract class AbstractFileWriter extends AbstractWriter
{
    /**
     * @var resource
     */
    protected $tempFile;

    protected string $tempPath;

    /**
     * @var resource
     */
    protected $buffer;

    public function __construct(protected FilesystemOperator $filesystem)
    {
        $this->initTempFile();
        $this->initBuffer();
    }

    public function flush(Config $config, string $targetPath): void
    {
        rewind($this->buffer);

        if (!\is_resource($this->tempFile)) {
            $file = fopen($this->tempPath, 'a+');
            if (!\is_resource($file)) {
                throw ImportExportException::couldNotOpenFile($this->tempPath);
            }
            $this->tempFile = $file;
        }

        $bytesCopied = stream_copy_to_stream($this->buffer, $this->tempFile);
        if ($bytesCopied === false) {
            throw ImportExportException::couldNotCopyFile($this->tempPath);
        }

        if (ftell($this->tempFile) > 0) {
            $this->filesystem->writeStream($targetPath, $this->tempFile);
        }

        $this->initBuffer();
    }

    public function finish(Config $config, string $targetPath): void
    {
        $this->flush($config, $targetPath);

        fclose($this->tempFile);
        unlink($this->tempPath);

        $this->initTempFile();
    }

    private function initTempFile(): void
    {
        $tempDir = sys_get_temp_dir();
        $tempFilePath = tempnam($tempDir, '');
        if (!\is_string($tempFilePath)) {
            throw ImportExportException::couldNotCreateFile($tempDir);
        }
        $this->tempPath = $tempFilePath;
        $file = fopen($this->tempPath, 'a+');
        if (!\is_resource($file)) {
            throw ImportExportException::couldNotOpenFile($this->tempPath);
        }
        $this->tempFile = $file;
    }

    private function initBuffer(): void
    {
        if (\is_resource($this->buffer)) {
            fclose($this->buffer);
        }
        $bufferPath = 'php://memory';
        $buffer = fopen($bufferPath, 'r+');
        if (!\is_resource($buffer)) {
            throw ImportExportException::couldNotOpenFile($bufferPath);
        }
        $this->buffer = $buffer;
    }
}
