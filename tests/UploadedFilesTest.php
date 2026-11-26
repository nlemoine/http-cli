<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\Runtime\UploadedFiles;
use PHPUnit\Framework\TestCase;

final class UploadedFilesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        UploadedFiles::reset();
        $this->directory = sys_get_temp_dir() . '/http-cli-uploaded-files-' . uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        UploadedFiles::reset();
        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    public function testRegisteredTmpNamesAreUploadedFiles(): void
    {
        UploadedFiles::register([
            'single' => [
                'tmp_name' => '/tmp/a',
            ],
            'many' => [
                'tmp_name' => ['/tmp/b', ['/tmp/c']],
            ],
        ]);

        $this->assertTrue(UploadedFiles::isUploaded('/tmp/a'));
        $this->assertTrue(UploadedFiles::isUploaded('/tmp/b'));
        $this->assertTrue(UploadedFiles::isUploaded('/tmp/c'));
        $this->assertFalse(UploadedFiles::isUploaded('/tmp/d'));
    }

    public function testEntriesWithoutAPathAreIgnored(): void
    {
        UploadedFiles::register([
            'empty' => [
                'tmp_name' => '',
                'error' => UPLOAD_ERR_NO_FILE,
            ],
            'malformed' => 'not an array',
            'no_tmp_name' => [
                'name' => 'a.txt',
            ],
        ]);

        $this->assertFalse(UploadedFiles::isUploaded(''));
    }

    public function testMoveRelocatesAnUploadOnce(): void
    {
        $from = $this->directory . '/upload';
        file_put_contents($from, 'content');
        UploadedFiles::register([
            'file' => [
                'tmp_name' => $from,
            ],
        ]);

        $this->assertTrue(UploadedFiles::move($from, $this->directory . '/moved'));
        $this->assertSame('content', file_get_contents($this->directory . '/moved'));
        $this->assertFileDoesNotExist($from);

        // The path no longer refers to an upload
        $this->assertFalse(UploadedFiles::isUploaded($from));
        $this->assertFalse(UploadedFiles::move($from, $this->directory . '/again'));
    }

    public function testMoveRefusesAFileThatIsNotAnUpload(): void
    {
        $from = $this->directory . '/other';
        file_put_contents($from, 'content');

        $this->assertFalse(UploadedFiles::move($from, $this->directory . '/moved'));
        $this->assertFileExists($from);
        $this->assertFileDoesNotExist($this->directory . '/moved');
    }
}
