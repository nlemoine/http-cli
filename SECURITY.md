# Security policy

`n5s/http-cli` runs PHP scripts from the command line as if they were served by a web server. This page explains how to report a security problem, and what does and doesn't count as a vulnerability.

## This is a side project

I maintain this library in my spare time. I'll always try to deal with reports as quickly as I can, but it can sometimes take me a while to reply. If you haven't heard back after a couple of weeks, feel free to add a comment to your report.

## Which versions get fixes

Only the latest `1.x` release gets security fixes. Please upgrade to the latest release and check that the problem still happens there before you report it.

| Version      | Gets security fixes |
| ------------ | ------------------- |
| 1.x (latest) | Yes                 |
| Older        | No                  |

## How to report a problem

Please report it privately through GitHub:

**[Report a vulnerability](https://github.com/nlemoine/http-cli/security/advisories/new)**

Please don't open a public issue, pull request or discussion about a security problem.

These things help me fix a problem quickly:

- **Something I can run.** A minimal document root and the few lines of PHP that call the client. Run it against the latest release or the `main` branch.
- **What went wrong.** What you expected to happen, what actually happened, and why it's unsafe.
- **How the client is used.** Whether you call `Client` directly or go through the Guzzle, Symfony HttpClient or WordPress Requests adapter, and where the request URL and options come from.
- **Your PHP version and operating system**, if the problem seems to depend on them.

You're welcome to use AI tools to find problems. Before you send a report, please run the example yourself and include what it printed. I'll close reports I can't reproduce.

## What happens after you report

1. I check that I can reproduce the problem.
2. If I can, I accept your report as a draft advisory and work on a fix in private.
3. I release the fix and publish the advisory, with a CVE where one applies.
4. I credit you in the advisory, unless you'd rather I didn't.

Before you report, please look through the [published advisories](https://github.com/nlemoine/http-cli/security/advisories) and the [CHANGELOG](CHANGELOG.md) in case it's already been fixed. Please keep the details to yourself until the advisory is published.

## What counts as a vulnerability

The client starts a PHP CLI process that runs a script from the document root you configure, as the same user and with the same permissions as the code that calls it. It replaces a web server, not a sandbox.

Please report these:

- **Running something other than the intended script.** A request URL or option that makes the client run a file outside the configured document root, run code chosen by whoever controls the request, or change how the PHP binary is started.
- **The executed script reaching into the calling process.** A way for the script's output to do more than produce a response, for example through the data the client reads back from the child process.
- **Leaks between requests.** Session data, superglobals or uploaded files from one request showing up in another.
- **Unsafe temporary files.** Uploaded file contents left behind on disk, or readable by other users.

These aren't vulnerabilities:

- **Anything the executed script does on its own.** It runs with your permissions on purpose. The functions the client disables are only replaced to emulate HTTP headers, not to restrict the script.
- **Environment variables of the calling process.** The PHP process inherits them, like any process you start, so `getenv()` can read them. Only `$_SERVER`, `$_ENV` and the other superglobals are rebuilt from the request.
- **Variables you choose to pass to the script**, with `InheritEnvGlobalsHandler` or your own `GlobalsHandler`.
- **Problems in Guzzle, Symfony HttpClient or WordPress Requests themselves.** Please report those to the project concerned.
- **Problems that only affect versions that don't get fixes.**
- **Differences from a real web server with no security impact.** Please open an ordinary issue instead.

If you're not sure whether something counts, report it privately anyway and I'll let you know.
