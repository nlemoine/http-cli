<?php

declare(strict_types=1);

namespace n5s\HttpCli;

use Exception;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final class Client
{
    private const PHP_DISABLED_FUNCTIONS = [
        'header',
        'header_remove',
        'headers_list',
        'headers_sent',
        'http_response_code',
        'php_sapi_name',
    ];

    private readonly string $documentRoot;

    public function __construct(
        string $documentRoot,
        private readonly ?string $file = 'index.php',
        private ?string $phpExecutable = null
    ) {
        $this->documentRoot = rtrim($documentRoot, '/\\');
    }

    /**
     * Request a URL using CLI PHP and return a Response object.
     */
    public function request(string $method, string $url, ?RequestOptions $options = null): Response
    {
        $options ??= RequestOptions::empty();

        // Determine which file to execute based on URL path
        $path = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo((string) $path, PATHINFO_EXTENSION);

        if ($extension === 'php' && is_string($path)) {
            // URL points to a specific PHP file - use it
            $fileToExecute = ltrim($path, '/');
        } else {
            // Use the default configured file
            $fileToExecute = $this->file;
        }

        $process = new Process(
            command: [
                $this->getPhpExecutable(),
                '-d', sprintf('auto_prepend_file=%s', __DIR__ . '/Runtime/bootstrap.php'),
                '-d', sprintf('disable_functions=%s', implode(',', self::PHP_DISABLED_FUNCTIONS)),
                $fileToExecute,
            ],
            cwd: $this->documentRoot,
        );

        // Prepare request parameters and content based on body options
        $parameters = [];
        $content = null;
        $contentType = null;
        $uploadedFiles = [];

        if (! empty($options->formParams)) {
            // Form parameters - will be available in $_POST
            $parameters = $options->formParams;
            $contentType = 'application/x-www-form-urlencoded';
        } elseif ($options->json !== null) {
            // JSON payload - will be available via php://input
            $content = json_encode($options->json, JSON_THROW_ON_ERROR);
            $contentType = 'application/json';
        } elseif ($options->body !== null) {
            // Raw body content - will be available via php://input
            $content = $options->body;
        } elseif (! empty($options->multipart)) {
            // Multipart form data - files go to $_FILES, fields to $_POST
            [$uploadedFiles, $postFields] = $this->processMultipart($options->multipart);
            $parameters = $postFields;
            $contentType = 'multipart/form-data';
        }

        // SCRIPT_NAME/PHP_SELF should reflect the URL path for PHP files, or configured file otherwise
        $scriptName = ($extension === 'php' && $path !== null) ? $path : '/' . $fileToExecute;

        $request = Request::create(
            uri: $url,
            method: $method,
            parameters: $parameters,
            server: [
                'DOCUMENT_ROOT' => $this->documentRoot,
                'SCRIPT_NAME' => $scriptName,
                'SCRIPT_FILENAME' => $this->documentRoot . '/' . $fileToExecute,
                'PHP_SELF' => $scriptName,
            ],
            content: $content
        );

        // Add uploaded files from multipart data
        foreach ($uploadedFiles as $name => $uploadedFile) {
            $request->files->set($name, $uploadedFile);
        }

        // Apply headers from options if provided
        if (! empty($options->headers)) {
            $request->headers->add($options->headers);
        }

        // Set Content-Type if determined from body options and not already set by user
        if ($contentType !== null && ! isset($options->headers['Content-Type'])) {
            $request->headers->set('Content-Type', $contentType);
        }

        // Apply HTTP Basic Authentication if provided and Authorization header not already set
        if (is_array($options->basicAuth) && ! $request->headers->has('Authorization')) {
            [$username, $password] = $options->basicAuth;
            $credentials = base64_encode($username . ':' . $password);
            $request->headers->set('Authorization', 'Basic ' . $credentials);
        }

        // Apply Bearer Token Authentication if provided and Authorization header not already set
        if (is_string($options->bearerToken) && ! $request->headers->has('Authorization')) {
            $request->headers->set('Authorization', 'Bearer ' . $options->bearerToken);
        }

        // Apply User-Agent if provided and not already set by user
        if (is_string($options->userAgent) && ! isset($options->headers['User-Agent'])) {
            $request->headers->set('User-Agent', $options->userAgent);
        }

        // Apply cookies if provided
        if (! empty($options->cookies)) {
            $request->cookies->add($options->cookies);
        }

        // Merge query parameters from options if provided
        // RequestOptions query params override URL query params for duplicate keys
        if (! empty($options->query)) {
            $request->query->add($options->query);
        }

        $globals = $this->getGlobals($request, $options->session);

        // Serialize globals data for STDIN transmission
        try {
            $process->setInput(serialize($globals));
        } catch (Exception $e) {
            throw new RuntimeException('Failed to serialize globals data: ' . $e->getMessage(), 0, $e);
        }

        if ($options->timeout !== null) {
            $process->setTimeout($options->timeout);
        }

        try {
            $process->mustRun();
            $output = $process->getOutput();
            // @phpstan-ignore catch.neverThrown (ProcessTimedOutException is thrown by Symfony Process on timeout)
        } catch (ProcessTimedOutException $e) {
            $output = $e->getProcess()->getOutput();
            $message = 'The process timed out';
            if ($e->isIdleTimeout()) {
                $message = 'The process timed out while idle';
            }

            throw new Exception($message);
        } catch (ProcessFailedException $e) {
            $output = $e->getProcess()->getOutput();
        }

        // Extract headers from output and clean content
        [$statusCode, $headers, $cleanContent, $session] = $this->parseHeadersFromOutput($output);

        return new Response(
            statusCode: $statusCode,
            headers: $headers,
            content: $cleanContent,
            process: $process,
            session: $session
        );
    }

    /**
     * Parse header marker from output and extract headers, status, session, and clean content.
     *
     * @param string $output The raw output from the PHP process
     * @return array{int, list<string>, string, array<string, mixed>} [statusCode, headers, cleanContent, session]
     */
    private function parseHeadersFromOutput(string $output): array
    {
        // Look for our header marker at the end of output
        $pattern = '/<!--HTTP_CLI_HEADERS:([A-Za-z0-9+\/=]+)-->$/';

        if (preg_match($pattern, $output, $matches)) {
            try {
                // Decode and unserialize header data
                $serializedData = base64_decode($matches[1], true);
                if ($serializedData === false) {
                    throw new RuntimeException('Failed to decode header data');
                }
                $headerData = @unserialize($serializedData);

                if (
                    is_array($headerData)
                    && isset($headerData['status'], $headerData['headers'])
                    && is_numeric($headerData['status'])
                ) {
                    // Remove header marker from content
                    $cleanContent = preg_replace($pattern, '', $output, 1);

                    if ($cleanContent === null) {
                        throw new RuntimeException('Failed to remove header marker from output');
                    }

                    /** @var list<string> $headers */
                    $headers = array_values((array) $headerData['headers']);
                    /** @var array<string, mixed> $session */
                    $session = (array) ($headerData['session'] ?? []);

                    return [
                        (int) $headerData['status'],
                        $headers,
                        $cleanContent,
                        $session,
                    ];
                }
            } catch (Exception) {
                // If parsing fails, fall through to defaults
            }
        }

        // No headers found or parsing failed - return defaults
        return [200, [], $output, []];
    }

    /**
     * Build PHP superglobals from the Symfony Request object
     *
     * @see https://github.com/symfony/http-foundation/blob/c0a241555575343b434f552580f0bbe9dd652ad7/Request.php#L504-L540
     *
     * @param array<string, mixed> $sessionData Session data to populate $_SESSION
     * @return array{
     *     _ENV: array<string, string>,
     *     _GET: array<string, mixed>,
     *     _POST: array<string, mixed>,
     *     _COOKIE: array<string, string>,
     *     _FILES: array<string, mixed>,
     *     _SESSION: array<string, mixed>,
     *     _SERVER: array<string, mixed>,
     *     _RAW_INPUT: string,
     *     _REQUEST: array<string, mixed>,
     * }
     */
    private function getGlobals(Request $request, array $sessionData = []): array
    {
        // Update QUERY_STRING in server array based on current query parameters
        $request->server->set('QUERY_STRING', Request::normalizeQueryString(http_build_query($request->query->all(), '', '&')));

        $globals = [
            '_ENV' => [],
            '_GET' => $request->query->all(),
            '_POST' => $request->request->all(),
            '_COOKIE' => $request->cookies->all(),
            '_FILES' => $request->files->all(),
            '_SESSION' => $sessionData,
            '_SERVER' => $request->server->all(),
            '_RAW_INPUT' => $request->getContent(), // For php://input simulation
        ];

        foreach ($request->headers->all() as $key => $value) {
            $key = strtoupper(str_replace('-', '_', $key));
            if (\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $globals['_SERVER'][$key] = implode(', ', $value);
            } else {
                $globals['_SERVER']['HTTP_' . $key] = implode(', ', $value);
            }
        }

        $requestData = [
            'g' => $globals['_GET'],
            'p' => $globals['_POST'],
            'c' => $globals['_COOKIE'],
        ];

        $requestOrder = \ini_get('request_order') ?: \ini_get('variables_order');
        $requestOrder = preg_replace('#[^cgp]#', '', strtolower((string) $requestOrder)) ?: 'gp';

        $globals['_REQUEST'] = [[]];
        foreach (str_split($requestOrder) as $order) {
            $globals['_REQUEST'][] = $requestData[$order];
        }

        $globals['_REQUEST'] = array_merge(...$globals['_REQUEST']);

        return $globals;
    }

    /**
     * Process multipart parts into uploaded files and form fields
     *
     * Parts with a 'filename' key are treated as file uploads and written to temp files.
     * Parts without 'filename' are treated as regular form fields.
     *
     * @param list<array{name: string, contents: string, filename?: string, headers?: array<string, string>}> $parts
     * @return array{array<string, UploadedFile>, array<string, string>} [files, fields]
     */
    private function processMultipart(array $parts): array
    {
        $files = [];
        $fields = [];

        foreach ($parts as $part) {
            $name = $part['name'];

            if (isset($part['filename'])) {
                // File upload - write contents to temp file
                $tmpFile = tempnam(sys_get_temp_dir(), 'http_cli_upload_');

                if ($tmpFile === false) {
                    throw new RuntimeException('Failed to create temporary file for upload');
                }

                file_put_contents($tmpFile, $part['contents']);

                // Detect MIME type from headers or file contents
                $mimeType = 'application/octet-stream';
                if (isset($part['headers']['Content-Type'])) {
                    $mimeType = $part['headers']['Content-Type'];
                } elseif (function_exists('mime_content_type')) {
                    $detectedType = mime_content_type($tmpFile);
                    if ($detectedType !== false) {
                        $mimeType = $detectedType;
                    }
                }

                // Register cleanup for temp file
                register_shutdown_function(static function () use ($tmpFile): void {
                    if (file_exists($tmpFile)) {
                        @unlink($tmpFile);
                    }
                });

                $files[$name] = new UploadedFile(
                    path: $tmpFile,
                    originalName: $part['filename'],
                    mimeType: $mimeType,
                    error: UPLOAD_ERR_OK,
                    test: true
                );
            } else {
                // Regular form field
                $fields[$name] = $part['contents'];
            }
        }

        return [$files, $fields];
    }

    /**
     * Get PHP executable path and memoize it.
     */
    private function getPhpExecutable(): string
    {
        return $this->phpExecutable ??= $this->getPhpExecutableAuto();
    }

    private function getPhpExecutableAuto(): string
    {
        $finder = new PhpExecutableFinder();
        $phpPath = $finder->find(true);

        if ($phpPath === false) {
            throw new RuntimeException('Unable to find PHP executable');
        }

        return $phpPath;
    }
}
