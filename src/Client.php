<?php

declare(strict_types=1);

namespace n5s\HttpCli;

use Exception;
use RuntimeException;
use stdClass;
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
        private readonly ?GlobalsHandler $globalsHandler = null,
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

        if (is_string($path) && pathinfo($path, PATHINFO_EXTENSION) === 'php') {
            // URL points to a specific PHP file, relative to the document root
            $script = $this->resolveScript(ltrim($path, '/'));
        } elseif ($this->file !== null) {
            // Use the default configured file
            $script = $this->resolveScript($this->file);
        } else {
            throw new RuntimeException('No file to execute: the client was constructed with a null $file and the URL does not point to a .php file.');
        }

        if ($script === null) {
            // Like a web server: the process is never started for a script that doesn't exist or is outside the document root
            return new Response(
                statusCode: 404,
                headers: ['Content-Type: text/plain; charset=UTF-8'],
                content: "File not found.\n",
                process: new Process([$this->getPhpExecutable()], $this->documentRoot),
            );
        }

        [$scriptName, $scriptFilename, $scriptRealPath] = $script;

        $process = new Process(
            command: [
                $this->getPhpExecutable(),
                '-d', sprintf('auto_prepend_file=%s', __DIR__ . '/Runtime/bootstrap.php'),
                '-d', sprintf('disable_functions=%s', implode(',', self::PHP_DISABLED_FUNCTIONS)),
                // Absolute path: it starts with a directory separator, so the PHP binary can't read it as an option
                $scriptRealPath,
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

        $request = Request::create(
            uri: $url,
            method: $method,
            parameters: $parameters,
            server: [
                'DOCUMENT_ROOT' => $this->documentRoot,
                'SCRIPT_NAME' => $scriptName,
                'SCRIPT_FILENAME' => $scriptFilename,
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

        // The status, headers and session come back through this file rather than the output, so that nothing the
        // script prints can change them. tempnam() creates it readable by the current user only.
        $metadataFile = tempnam(sys_get_temp_dir(), 'http_cli_metadata_');

        if ($metadataFile === false) {
            throw new RuntimeException('Failed to create the temporary file for the response metadata');
        }

        try {
            // Serialize globals data for STDIN transmission
            try {
                $process->setInput(serialize([
                    'globals' => $globals,
                    'metadataFile' => $metadataFile,
                ]));
            } catch (Exception $e) {
                throw new RuntimeException('Failed to serialize globals data: ' . $e->getMessage(), 0, $e);
            }

            if ($options->timeout !== null) {
                $process->setTimeout($options->timeout);
            }

            try {
                $process->mustRun();
                $output = $process->getOutput();
            } catch (ProcessTimedOutException $e) {
                $message = 'The process timed out';
                if ($e->isIdleTimeout()) {
                    $message = 'The process timed out while idle';
                }

                throw new Exception($message);
            } catch (ProcessFailedException $e) {
                $output = $e->getProcess()->getOutput();
            }

            [$statusCode, $headers, $session] = $this->readMetadata($metadataFile);
        } finally {
            @unlink($metadataFile);
        }

        return new Response(
            statusCode: $statusCode,
            headers: $headers,
            content: $output,
            process: $process,
            session: $session
        );
    }

    /**
     * Resolve the script to execute, confined to the document root.
     *
     * The path is first normalized lexically ("." and ".." segments, repeated separators), as a web server does with the
     * request path, so that SCRIPT_NAME and SCRIPT_FILENAME match what it would set. The result is then resolved with
     * realpath(), so that a symbolic link can't lead outside the document root either.
     *
     * @param string $file Path relative to the document root, or absolute filesystem path
     * @return array{string, string, string}|null [scriptName, scriptFilename, realPath], or null when the script
     *                                            doesn't exist, isn't a file, or is outside the document root
     */
    private function resolveScript(string $file): ?array
    {
        $documentRootRealPath = realpath($this->documentRoot);

        if ($documentRootRealPath === false || ! is_dir($documentRootRealPath)) {
            throw new RuntimeException(sprintf('The document root "%s" does not exist or is not a directory.', $this->documentRoot));
        }

        if (str_contains($file, "\0")) {
            return null;
        }

        $normalizeSeparators = static fn (string $path): string => DIRECTORY_SEPARATOR === '\\' ? str_replace('\\', '/', $path) : $path;
        $file = $normalizeSeparators($file);

        // An absolute filesystem path must start with the document root
        if (str_starts_with($file, '/') || preg_match('#^[A-Za-z]:/#', $file) === 1) {
            $relativePath = null;

            foreach ([$this->documentRoot, $documentRootRealPath] as $prefix) {
                $prefix = rtrim($normalizeSeparators($prefix), '/') . '/';

                if (str_starts_with($file, $prefix)) {
                    $relativePath = substr($file, strlen($prefix));
                    break;
                }
            }

            if ($relativePath === null) {
                return null;
            }
        } else {
            $relativePath = $file;
        }

        $segments = [];
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    // Above the document root
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            return null;
        }

        $relativePath = implode('/', $segments);
        $scriptFilename = $this->documentRoot . '/' . $relativePath;
        $realPath = realpath($scriptFilename);

        if (
            $realPath === false
            || ! is_file($realPath)
            || ! str_starts_with($realPath, rtrim($documentRootRealPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        ) {
            return null;
        }

        return ['/' . $relativePath, $scriptFilename, $realPath];
    }

    /**
     * Read the status, headers and session that the child process wrote to the metadata file.
     *
     * Objects other than stdClass, which has no methods, are not instantiated in the calling process: they come back as
     * __PHP_Incomplete_Class, which serializes back to the original object when the session is passed to the next
     * request.
     *
     * @return array{int, list<string>, array<string, mixed>} [statusCode, headers, session]
     */
    private function readMetadata(string $metadataFile): array
    {
        $defaults = [200, [], []];

        $serializedMetadata = @file_get_contents($metadataFile);

        if ($serializedMetadata === false || $serializedMetadata === '') {
            // The process ended before its shutdown functions could run
            return $defaults;
        }

        $metadata = @unserialize($serializedMetadata, [
            'allowed_classes' => [stdClass::class],
        ]);

        if (
            ! is_array($metadata)
            || ! isset($metadata['status'], $metadata['headers'])
            || ! is_int($metadata['status'])
            || ! is_array($metadata['headers'])
        ) {
            return $defaults;
        }

        /** @var array<string, mixed> $session */
        $session = is_array($metadata['session'] ?? null) ? $metadata['session'] : [];

        return [
            $metadata['status'],
            array_values(array_filter($metadata['headers'], is_string(...))),
            $session,
        ];
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

        $this->globalsHandler?->handle($globals);

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
