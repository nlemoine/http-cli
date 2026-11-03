<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\Client;
use n5s\HttpCli\RequestOptions;
use RuntimeException;

/**
 * Temporary files created for a request must be gone once the request is over, not when the calling process exits.
 */
final class ClientTemporaryFilesTest extends AbstractHttpCliTestCase
{
    public function testUploadTemporaryFilesAreRemovedAfterTheRequest(): void
    {
        $this->createTestFile('upload', '<?php echo count($_FILES);');
        $pattern = sys_get_temp_dir() . '/http_cli_upload_*';
        $before = glob($pattern);

        try {
            (new Client($this->testDocumentRoot))->request('POST', 'http://localhost/upload.php', new RequestOptions(multipart: [[
                'name' => 'document',
                'contents' => 'file contents',
                'filename' => 'document.txt',
            ]]));
        } catch (RuntimeException) {
            // Requests with files still fail while the UploadedFile objects can't be serialized: the files must be removed either way
        }

        $this->assertSame($before, glob($pattern));
    }
}
