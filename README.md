# HTTP CLI

Serverless HTTP client - make requests to PHP scripts on the CLI.

This library lets you make HTTP requests to PHP applications without running a web server. Instead of Apache or nginx, it executes your PHP scripts directly via the command line while emulating the full HTTP environment (`$_GET`, `$_POST`, `$_SERVER`, `$_SESSION`, headers, cookies, etc.).

Perfect for testing, CI pipelines, unreleased deployment or any scenario where spinning up a web server is overkill or not possible.

## Installation

```bash
composer require n5s/http-cli
```

## Usage

```php
use n5s\HttpCli\Client;
use n5s\HttpCli\RequestOptions;

$client = new Client('/path/to/your/app');

// Simple GET request
$response = $client->request('GET', 'https://example.com/api/users');
echo $response->getContent();

// POST with JSON
$response = $client->request('POST', '/api/users',
    RequestOptions::create()
        ->json(['name' => 'John', 'email' => 'john@example.com'])
        ->build()
);

// POST with form data
$response = $client->request('POST', '/login',
    RequestOptions::create()
        ->formParams(['username' => 'admin', 'password' => 'secret'])
        ->build()
);
```

## How It Works

When you make a request, the library:

1. Spawns a PHP CLI process targeting your script
2. Injects a bootstrap that populates `$_GET`, `$_POST`, `$_SERVER`, `$_COOKIE`, and `$_SESSION`
3. Provides polyfills for specific HTTP context functions: `header()`, `headers_sent()`, `http_response_code()`, etc.
4. Captures the output and headers, returning a `Response` object

Your PHP scripts run exactly as they would under a web server, but without one.

## Framework Adapters

Use your favorite HTTP client library - just swap in our handler.

### Guzzle

```bash
composer require guzzlehttp/guzzle
```

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use n5s\HttpCli\Guzzle\CliHandler;
use n5s\HttpCli\Client;

$cliClient = new Client('/path/to/your/app');
$handler = new CliHandler($cliClient);

$client = new Client([
    'handler' => HandlerStack::create($handler),
]);

// Use Guzzle as normal
$response = $client->get('/api/users');
$response = $client->post('/api/users', [
    'json' => ['name' => 'John'],
]);
```

### Symfony HttpClient

```bash
composer require symfony/http-client
```

```php
use n5s\HttpCli\Symfony\CliClient;
use n5s\HttpCli\Client;

$cliClient = new Client('/path/to/your/app');
$client = new CliClient($cliClient);

// Use Symfony HttpClient as normal
$response = $client->request('GET', '/api/users');
$data = $response->toArray();
```

### WordPress Requests

```bash
composer require rmccue/requests
```

```php
use WpOrg\Requests\Requests;
use n5s\HttpCli\WordPress\Cli;
use n5s\HttpCli\Client;

$cliClient = new Client('/path/to/your/app');

Requests::set_transport([Cli::class]);
Cli::setClient($cliClient);

// Use WordPress Requests as normal
$response = Requests::get('/api/users');
```

## Request Options

Build requests with a fluent API:

```php
use n5s\HttpCli\RequestOptions;

$options = RequestOptions::create()
    // Body
    ->json(['key' => 'value'])           // JSON payload
    ->formParams(['field' => 'value'])   // Form data (application/x-www-form-urlencoded)
    ->body('raw content')                // Raw body
    ->multipart([                        // Multipart form data
        ['name' => 'file', 'contents' => 'data', 'filename' => 'test.txt'],
    ])

    // Headers & Auth
    ->headers(['X-Custom' => 'value'])
    ->basicAuth('user', 'pass')
    ->bearerToken('token')
    ->cookies(['session' => 'abc123'])

    // Other
    ->query(['page' => 1, 'limit' => 10])
    ->timeout(30.0)
    ->build();

$response = $client->request('POST', '/api/endpoint', $options);
```

## Response

```php
$response = $client->request('GET', '/api/users');

$response->getStatusCode();  // 200
$response->getHeaders();     // ['Content-Type: application/json', ...]
$response->getContent();     // Response body as string
$response->getSession();     // Session data array
$response->getProcess();     // Symfony Process instance (for debugging)
```

## Configuration

```php
$client = new Client(
    documentRoot: '/path/to/your/app',   // Required: your app's root directory
    file: 'index.php',                   // Entry point (default: index.php)
    phpExecutable: null,                 // PHP binary path (auto-detected)
);
```

## Adapter Options Support

### Guzzle

| Option | Supported |
|--------|-----------|
| `timeout` | ✅ |
| `headers` | ✅ |
| `query` | ✅ |
| `body` | ✅ |
| `json` | ✅ |
| `form_params` | ✅ |
| `multipart` | ✅ |
| `auth` | ✅ |
| `cookies` | ✅ |
| `allow_redirects` | ✅ |
| `http_errors` | ✅ |
| `decode_content` | ✅ |
| `version` | ✅ |
| `sink` | ✅ |
| `on_headers` | ✅ (callback) |
| `on_stats` | ✅ (callback) |
| `connect_timeout` | ❌ ignored |
| `verify` | ❌ ignored |
| `cert` | ❌ ignored |
| `proxy` | ❌ ignored |
| `ssl_key` | ❌ |
| `progress` | ❌ |
| `debug` | ❌ |

### Symfony HttpClient

| Option | Supported |
|--------|-----------|
| `timeout` | ✅ |
| `headers` | ✅ |
| `query` | ✅ |
| `body` | ✅ |
| `json` | ✅ |
| `auth_basic` | ✅ |
| `auth_bearer` | ✅ |
| `max_redirects` | ✅ |
| `verify_peer` | ❌ ignored |
| `verify_host` | ❌ ignored |
| `cafile` | ❌ ignored |
| `proxy` | ❌ ignored |
| `http_version` | ❌ |
| `on_progress` | ❌ |
| `resolve` | ❌ |
| `local_cert` | ❌ |
| `local_pk` | ❌ |
| `ciphers` | ❌ |

### WordPress Requests

| Option | Supported |
|--------|-----------|
| `timeout` | ✅ |
| `useragent` | ✅ |
| `redirects` | ✅ |
| `follow_redirects` | ✅ |
| `auth` | ✅ |
| `cookies` | ✅ |
| `connect_timeout` | ❌ ignored |
| `proxy` | ❌ ignored |
| `verify` | ❌ ignored |
| `verifyname` | ❌ ignored |
| `filename` | ❌ |
| `hooks` | ❌ |
| `max_bytes` | ❌ |

## Limitations

Running PHP scripts via CLI instead of a web server comes with inherent limitations:

### Not Supported

| Feature | Reason |
|---------|--------|
| Persistent connections | Each request spawns a new PHP process |
| Keep-alive | No connection reuse between requests |
| HTTP/2, HTTP/3 | CLI execution doesn't use HTTP protocol |
| WebSockets | Requires persistent connection |
| Server-Sent Events | Requires streaming connection |
| Real SSL/TLS | No actual HTTPS handshake (URLs are parsed, not connected) |
| Output streaming | Response is captured after script completes |
| `fastcgi_finish_request()` | FPM-specific function |
| APCu user cache | Not shared between CLI processes |
| OPcache benefits | Each process starts fresh |
| Static files | Only executes PHP - images, fonts, CSS, JS won't be served |

### Behavioral Differences

- **Performance**: Process spawning overhead, but no DNS resolution, TCP/SSL handshake, or network latency
- **`$_SERVER` values**: Some values like `SERVER_SOFTWARE` will differ from Apache/nginx
- **File uploads**: Multipart parts are written to temp files and populated in `$_FILES`
- **Session handling**: Works but uses an in-memory handler, not file-based persistence
- **`php://input`**: Custom stream wrapper provides the request body

## License

MIT
