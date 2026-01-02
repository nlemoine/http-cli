<?php

declare(strict_types=1);

namespace n5s\HttpCli\Tests;

use n5s\HttpCli\Runtime\InputStream;
use PHPUnit\Framework\TestCase;

/**
 * Tests for InputStream stream wrapper
 */
class InputStreamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        InputStream::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Restore original php:// wrapper
        InputStream::unregister();
    }

    public function testStreamWrapperRegistration(): void
    {
        $this->assertTrue(InputStream::register());
        $this->assertContains('php', stream_get_wrappers());
    }

    public function testStreamWrapperCanBeUnregistered(): void
    {
        InputStream::register();
        $this->assertTrue(InputStream::unregister());
    }

    public function testCanReadRawInputData(): void
    {
        InputStream::register();
        InputStream::setRawInput('test data');

        $data = file_get_contents('php://input');

        $this->assertEquals('test data', $data);
    }

    public function testReadOnceSemantics(): void
    {
        InputStream::register();
        InputStream::setRawInput('test data');

        $firstRead = file_get_contents('php://input');
        $secondRead = file_get_contents('php://input');

        $this->assertEquals('test data', $firstRead);
        $this->assertEquals('', $secondRead, 'php://input should only be readable once');
    }

    public function testCanReadJsonData(): void
    {
        InputStream::register();
        $jsonData = json_encode([
            'key' => 'value',
            'number' => 42,
        ]);
        InputStream::setRawInput($jsonData);

        $rawInput = file_get_contents('php://input');
        $decoded = json_decode($rawInput, true);

        $this->assertEquals($jsonData, $rawInput);
        $this->assertEquals([
            'key' => 'value',
            'number' => 42,
        ], $decoded);
    }

    public function testCanReadEmptyInput(): void
    {
        InputStream::register();
        InputStream::setRawInput('');

        $data = file_get_contents('php://input');

        $this->assertEquals('', $data);
    }

    public function testCanReadLargeInput(): void
    {
        InputStream::register();
        $largeData = str_repeat('A', 1024 * 100); // 100KB
        InputStream::setRawInput($largeData);

        $data = file_get_contents('php://input');

        $this->assertEquals($largeData, $data);
        $this->assertEquals(1024 * 100, strlen($data));
    }

    public function testCanReadBinaryData(): void
    {
        InputStream::register();
        $binaryData = "\x00\x01\x02\x03\xFF\xFE\xFD";
        InputStream::setRawInput($binaryData);

        $data = file_get_contents('php://input');

        $this->assertEquals($binaryData, $data);
    }

    public function testStreamCanBeOpenedMultipleTimes(): void
    {
        InputStream::register();
        InputStream::setRawInput('test data');

        // First open and read
        $fp1 = fopen('php://input', 'r');
        $data1 = stream_get_contents($fp1);
        fclose($fp1);

        // Second open - should return empty due to read-once semantics
        $fp2 = fopen('php://input', 'r');
        $data2 = stream_get_contents($fp2);
        fclose($fp2);

        $this->assertEquals('test data', $data1);
        $this->assertEquals('', $data2);
    }

    public function testStreamStatReturnsCorrectSize(): void
    {
        InputStream::register();
        $testData = 'test data with some length';
        InputStream::setRawInput($testData);

        $fp = fopen('php://input', 'r');
        $stat = fstat($fp);
        fclose($fp);

        $this->assertEquals(strlen($testData), $stat['size']);
    }

    public function testStreamCanBeReadInChunks(): void
    {
        InputStream::register();
        InputStream::setRawInput('0123456789');

        $fp = fopen('php://input', 'r');
        $chunk1 = fread($fp, 3);
        $chunk2 = fread($fp, 3);
        $chunk3 = fread($fp, 10); // Read remaining
        fclose($fp);

        $this->assertEquals('012', $chunk1);
        $this->assertEquals('345', $chunk2);
        $this->assertEquals('6789', $chunk3);
    }

    public function testResetClearsRawInput(): void
    {
        InputStream::register();
        InputStream::setRawInput('test data');
        InputStream::reset();

        $data = file_get_contents('php://input');

        $this->assertEquals('', $data);
    }

    public function testMultibyteUnicodeData(): void
    {
        InputStream::register();
        $unicodeData = 'Hello 世界 🚀';
        InputStream::setRawInput($unicodeData);

        $data = file_get_contents('php://input');

        $this->assertEquals($unicodeData, $data);
    }

    // Other php:// paths must still work
    public function testPhpOutputStillWorks(): void
    {
        InputStream::register();

        $handle = fopen('php://output', 'w');
        $this->assertNotFalse($handle, 'php://output should be openable after registration');

        $bytesWritten = fwrite($handle, 'test');
        $this->assertEquals(4, $bytesWritten, 'php://output should accept writes');

        fclose($handle);
    }

    public function testPhpMemoryStillWorks(): void
    {
        InputStream::register();

        $handle = fopen('php://memory', 'r+');
        $this->assertNotFalse($handle, 'php://memory should be openable after registration');

        // Write
        $bytesWritten = fwrite($handle, 'test data');
        $this->assertEquals(9, $bytesWritten);

        // Seek back
        rewind($handle);

        // Read
        $data = fread($handle, 100);
        $this->assertEquals('test data', $data);

        fclose($handle);
    }

    public function testPhpTempStillWorks(): void
    {
        InputStream::register();

        $handle = fopen('php://temp', 'r+');
        $this->assertNotFalse($handle, 'php://temp should be openable after registration');

        // Write
        $bytesWritten = fwrite($handle, 'temp data');
        $this->assertEquals(9, $bytesWritten);

        // Seek back
        rewind($handle);

        // Read
        $data = fread($handle, 100);
        $this->assertEquals('temp data', $data);

        fclose($handle);
    }

    public function testPhpStdoutStillWorks(): void
    {
        InputStream::register();

        $handle = fopen('php://stdout', 'w');
        $this->assertNotFalse($handle, 'php://stdout should be openable after registration');

        $bytesWritten = fwrite($handle, 'stdout test');
        $this->assertEquals(11, $bytesWritten);

        fclose($handle);
    }

    public function testPhpStderrStillWorks(): void
    {
        InputStream::register();

        $handle = fopen('php://stderr', 'w');
        $this->assertNotFalse($handle, 'php://stderr should be openable after registration');

        $bytesWritten = fwrite($handle, 'stderr test');
        $this->assertEquals(11, $bytesWritten);

        fclose($handle);
    }

    public function testPhpMemorySeekAndTell(): void
    {
        InputStream::register();

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, '0123456789');

        // Test tell
        $this->assertEquals(10, ftell($handle));

        // Test seek
        fseek($handle, 5);
        $this->assertEquals(5, ftell($handle));

        // Read from position
        $data = fread($handle, 5);
        $this->assertEquals('56789', $data);

        fclose($handle);
    }

    public function testPhpInputAndOutputCanBeUsedTogether(): void
    {
        InputStream::register();
        InputStream::setRawInput('input data');

        // Read from input
        $inputData = file_get_contents('php://input');
        $this->assertEquals('input data', $inputData);

        // Write to output should still work
        $handle = fopen('php://output', 'w');
        $this->assertNotFalse($handle);
        $bytesWritten = fwrite($handle, 'output data');
        $this->assertEquals(11, $bytesWritten);
        fclose($handle);
    }

    // EOF Tests
    public function testStreamEofForInput(): void
    {
        InputStream::register();
        InputStream::setRawInput('test');

        $handle = fopen('php://input', 'r');
        $this->assertFalse(feof($handle));

        fread($handle, 100); // Read all
        $this->assertTrue(feof($handle));

        fclose($handle);
    }

    public function testStreamEofForMemory(): void
    {
        InputStream::register();

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, 'test data');
        rewind($handle);

        $this->assertFalse(feof($handle));
        fread($handle, 100);
        $this->assertTrue(feof($handle));

        fclose($handle);
    }

    // Seek Tests for php://input
    public function testSeekInputWithSeekSet(): void
    {
        InputStream::register();
        InputStream::setRawInput('0123456789');

        $handle = fopen('php://input', 'r');
        fread($handle, 5); // Read to position 5

        $result = fseek($handle, 2, SEEK_SET);
        $this->assertEquals(0, $result);
        $this->assertEquals(2, ftell($handle));

        $data = fread($handle, 3);
        $this->assertEquals('234', $data);

        fclose($handle);
    }

    public function testSeekInputWithSeekCur(): void
    {
        InputStream::register();
        InputStream::setRawInput('0123456789');

        $handle = fopen('php://input', 'r');
        fread($handle, 3); // Position at 3

        fseek($handle, 2, SEEK_CUR); // Move forward 2
        $this->assertEquals(5, ftell($handle));

        fclose($handle);
    }

    public function testSeekInputWithSeekEnd(): void
    {
        InputStream::register();
        InputStream::setRawInput('0123456789');

        $handle = fopen('php://input', 'r');

        fseek($handle, -3, SEEK_END); // 3 from end
        $this->assertEquals(7, ftell($handle));

        $data = fread($handle, 10);
        $this->assertEquals('789', $data);

        fclose($handle);
    }

    public function testSeekInputAfterConsumedReturnsFalse(): void
    {
        InputStream::register();
        InputStream::setRawInput('test');

        // First read - consumes the stream
        file_get_contents('php://input');

        // Try to seek in a new handle - should fail
        $handle = fopen('php://input', 'r');
        $result = fseek($handle, 0, SEEK_SET);
        $this->assertEquals(-1, $result);

        fclose($handle);
    }

    public function testSeekInputBeyondBoundsReturnsFalse(): void
    {
        InputStream::register();
        InputStream::setRawInput('test');

        $handle = fopen('php://input', 'r');

        // Seek before start
        $result = fseek($handle, -5, SEEK_SET);
        $this->assertEquals(-1, $result);

        // Seek beyond end
        $result = fseek($handle, 100, SEEK_SET);
        $this->assertEquals(-1, $result);

        fclose($handle);
    }

    // Seek Tests for php://memory
    public function testSeekMemoryWithSeekCur(): void
    {
        InputStream::register();

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, '0123456789');
        rewind($handle);

        fread($handle, 3); // Position at 3
        fseek($handle, 2, SEEK_CUR);
        $this->assertEquals(5, ftell($handle));

        fclose($handle);
    }

    public function testSeekMemoryWithSeekEnd(): void
    {
        InputStream::register();

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, '0123456789');

        fseek($handle, -3, SEEK_END);
        $this->assertEquals(7, ftell($handle));

        fclose($handle);
    }

    public function testSeekMemoryBeyondEndAllowed(): void
    {
        InputStream::register();

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, 'test');
        rewind($handle);

        // Seeking beyond end is allowed for memory streams
        $result = fseek($handle, 100, SEEK_SET);
        $this->assertEquals(0, $result);
        $this->assertEquals(100, ftell($handle));

        fclose($handle);
    }

    public function testSeekMemoryBeforeStartFails(): void
    {
        InputStream::register();

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, 'test');
        rewind($handle);

        $result = fseek($handle, -5, SEEK_SET);
        $this->assertEquals(-1, $result);

        fclose($handle);
    }

    // Write Tests
    public function testWriteToInputReturnsZero(): void
    {
        InputStream::register();
        InputStream::setRawInput('test');

        $handle = fopen('php://input', 'r');
        $bytesWritten = @fwrite($handle, 'new data');

        // php://input is read-only, should return 0
        $this->assertEquals(0, $bytesWritten);

        fclose($handle);
    }

    public function testWriteToMemoryAtPosition(): void
    {
        InputStream::register();

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, '0123456789');

        // Seek to middle and overwrite
        fseek($handle, 3);
        fwrite($handle, 'XXX');

        // Read result
        rewind($handle);
        $data = fread($handle, 100);
        $this->assertEquals('012XXX6789', $data);

        fclose($handle);
    }

    // Truncate Tests
    public function testTruncateMemory(): void
    {
        InputStream::register();

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, '0123456789');

        $result = ftruncate($handle, 5);
        $this->assertTrue($result);

        rewind($handle);
        $data = fread($handle, 100);
        $this->assertEquals('01234', $data);

        fclose($handle);
    }

    public function testTruncateMemoryExpands(): void
    {
        InputStream::register();

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, 'test');

        $result = ftruncate($handle, 10);
        $this->assertTrue($result);

        $stat = fstat($handle);
        $this->assertEquals(10, $stat['size']);

        fclose($handle);
    }

    public function testTruncateTemp(): void
    {
        InputStream::register();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, '0123456789');

        $result = ftruncate($handle, 3);
        $this->assertTrue($result);

        rewind($handle);
        $data = fread($handle, 100);
        $this->assertEquals('012', $data);

        fclose($handle);
    }

    // Flush Tests
    public function testFlushMemory(): void
    {
        InputStream::register();

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, 'test');

        $result = fflush($handle);
        $this->assertTrue($result);

        fclose($handle);
    }

    public function testFlushStdout(): void
    {
        InputStream::register();

        $handle = fopen('php://stdout', 'w');
        fwrite($handle, 'test');

        $result = fflush($handle);
        $this->assertTrue($result);

        fclose($handle);
    }

    // stream_stat Tests
    public function testStreamStatForMemory(): void
    {
        InputStream::register();

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, 'test data here');

        $stat = fstat($handle);
        $this->assertEquals(14, $stat['size']);
        $this->assertEquals(33206, $stat['mode']); // Regular file mode

        fclose($handle);
    }

    public function testStreamStatForTemp(): void
    {
        InputStream::register();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, 'temp data');

        $stat = fstat($handle);
        $this->assertEquals(9, $stat['size']);

        fclose($handle);
    }

    // Unregister Edge Cases
    public function testUnregisterRestoresOriginalWrapper(): void
    {
        InputStream::register();
        InputStream::setRawInput('test');

        // Verify our wrapper is active
        $data = file_get_contents('php://input');
        $this->assertEquals('test', $data);

        // Unregister restores original
        $result = InputStream::unregister();
        $this->assertTrue($result);

        // Verify original php:// wrapper is restored by checking php://memory works
        $handle = fopen('php://memory', 'r+');
        $this->assertIsResource($handle);
        fclose($handle);
    }

    public function testReRegisterAfterUnregister(): void
    {
        InputStream::register();
        InputStream::setRawInput('first');

        file_get_contents('php://input');

        InputStream::unregister();
        InputStream::register();
        InputStream::setRawInput('second');

        $data = file_get_contents('php://input');
        $this->assertEquals('second', $data);
    }

    // setRawInput resetting consumed flag
    public function testSetRawInputResetsConsumedFlag(): void
    {
        InputStream::register();
        InputStream::setRawInput('first read');

        // Consume the stream
        $first = file_get_contents('php://input');
        $this->assertEquals('first read', $first);

        // Set new data - should reset consumed flag
        InputStream::setRawInput('second read');

        $second = file_get_contents('php://input');
        $this->assertEquals('second read', $second);
    }

    // php://temp with maxmemory
    public function testPhpTempWithMaxMemory(): void
    {
        InputStream::register();

        // php://temp/maxmemory:1024 should work
        $handle = fopen('php://temp/maxmemory:1024', 'r+');
        $this->assertNotFalse($handle);

        fwrite($handle, 'temp with limit');
        rewind($handle);
        $data = fread($handle, 100);
        $this->assertEquals('temp with limit', $data);

        fclose($handle);
    }

    // Empty reads
    public function testReadFromEmptyMemory(): void
    {
        InputStream::register();

        $handle = fopen('php://memory', 'r+');
        $data = fread($handle, 100);
        $this->assertEquals('', $data);

        fclose($handle);
    }

    public function testReadAfterEndOfMemory(): void
    {
        InputStream::register();

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, 'test');
        // Don't rewind - we're at the end

        $data = fread($handle, 100);
        $this->assertEquals('', $data);

        fclose($handle);
    }

    // php://input tell
    public function testInputTell(): void
    {
        InputStream::register();
        InputStream::setRawInput('0123456789');

        $handle = fopen('php://input', 'r');
        $this->assertEquals(0, ftell($handle));

        fread($handle, 5);
        $this->assertEquals(5, ftell($handle));

        fread($handle, 3);
        $this->assertEquals(8, ftell($handle));

        fclose($handle);
    }
}
