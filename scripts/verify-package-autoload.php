<?php

declare(strict_types=1);

/**
 * Check that an assembled app package can actually autoload what it declares.
 *
 * The packaging step copies the repository with a list of rsync excludes, and an
 * exclude written without a leading slash matches at any depth. That is how a
 * pattern meant for this app's own `src/` came to delete every `src` directory
 * inside `vendor/` as well: composer's autoload maps still pointed there, the
 * package still carried a plausible-looking `vendor/` tree, and the release
 * passed every check we had. It failed for the first time in production, on the
 * first Assistant message a user sent, with `Class "Mcp\Client" not found`.
 *
 * So this asserts the property the excludes can silently break: every directory
 * composer's generated maps reference exists in the package, and a class from
 * each vendored namespace really loads. Run against the assembled directory:
 *
 *     php scripts/verify-package-autoload.php build/artifacts/astrolabe
 */

$package = $argv[1] ?? '';
if ($package === '' || !is_dir($package)) {
	fwrite(STDERR, "usage: verify-package-autoload.php <assembled-package-dir>\n");
	exit(2);
}
$package = realpath($package);

$errors = [];

$autoload = $package . '/vendor/autoload.php';
if (!is_file($autoload)) {
	fwrite(STDERR, "error: {$autoload} is missing; the package cannot load any dependency\n");
	exit(1);
}

/**
 * Paths referenced by composer's generated maps, as absolute paths.
 *
 * The maps are plain PHP returning arrays built from `$vendorDir`/`$baseDir`,
 * so including them resolves the paths against wherever the package now lives.
 * `autoload_files.php` is included because those are `require`d eagerly the
 * moment `vendor/autoload.php` runs: one of them missing is a fatal error
 * before any of this app's code executes.
 *
 * @return array{dirs: list<string>, files: list<string>}
 */
function mappedPaths(string $vendorDir): array {
	$dirs = [];
	$files = [];

	foreach (['autoload_psr4.php', 'autoload_namespaces.php'] as $name) {
		$path = $vendorDir . '/composer/' . $name;
		if (!is_file($path)) {
			continue;
		}
		// `require` is deliberate here and below: these files are data, read for
		// the array they return. `require_once` would evaluate to `true` rather
		// than the map if the file had already been included — exactly the case
		// this check exists to survive.
		/** @var array<string, list<string>|string> $map */
		$map = require $path; // NOSONAR (php:S2003) — needs the returned map.
		foreach ($map as $paths) {
			foreach ((array)$paths as $dir) {
				$dirs[] = (string)$dir;
			}
		}
	}

	$classmap = $vendorDir . '/composer/autoload_classmap.php';
	if (is_file($classmap)) {
		/** @var array<string, string> $map */
		$map = require $classmap; // NOSONAR (php:S2003) — see above; needs the returned map.
		foreach ($map as $file) {
			$dirs[] = dirname((string)$file);
		}
	}

	$includes = $vendorDir . '/composer/autoload_files.php';
	if (is_file($includes)) {
		/** @var array<string, string> $map */
		$map = require $includes; // NOSONAR (php:S2003) — see above; needs the returned map.
		foreach ($map as $file) {
			$files[] = (string)$file;
		}
	}

	return [
		'dirs' => array_values(array_unique($dirs)),
		'files' => array_values(array_unique($files)),
	];
}

// Only what composer installed: the maps also carry this app's own entries,
// including the `autoload-dev` ones pointing at `tests/`, which the package is
// meant to leave behind. `lib/` is checked on its own below.
$vendorDir = $package . '/vendor/';
$mapped = mappedPaths($package . '/vendor');
$missing = [
	...array_filter(
		$mapped['dirs'],
		static fn (string $dir): bool => str_starts_with($dir, $vendorDir) && !is_dir($dir),
	),
	...array_filter(
		$mapped['files'],
		static fn (string $file): bool => str_starts_with($file, $vendorDir) && !is_file($file),
	),
];

// Reported before loading anything: requiring an autoloader that points at
// files which are not there is a fatal, and a stack trace hides the one thing
// worth saying — which paths the package is missing.
if ($missing !== []) {
	$relative = array_map(
		static fn (string $path): string => str_replace($package . '/', '', $path),
		array_values($missing),
	);
	sort($relative);
	fwrite(STDERR, sprintf(
		"error: %d path(s) referenced by composer's autoloader are not in the package:\n  - %s\n",
		count($relative),
		implode("\n  - ", array_slice($relative, 0, 20)),
	));
	fwrite(STDERR, "\nCheck the rsync excludes in the Makefile's `assemble` target:\n");
	fwrite(STDERR, "a pattern without a leading slash matches at any depth, including inside vendor/.\n");
	exit(1);
}

/**
 * One class per runtime dependency, to catch a tree that is present but hollow.
 *
 * Checked by loading them for real: a stripped package leaves the maps intact,
 * so only an actual resolution proves the files behind them survived.
 *
 * Hand-maintained, so keep it in step with the runtime `require` entries in
 * `composer.json`: a dependency added there and not here simply stops being
 * covered, silently. That is a weaker failure than it sounds — the path check
 * above walks every entry composer generated, so a directory vanishing from the
 * package is still caught for dependencies missing from this list. What is lost
 * is only the "present but hollow" case.
 */
const REQUIRED_CLASSES = [
	'Mcp\Client',
	'Symfony\Component\Uid\Uuid',
	// A transitive dependency rather than a direct one — `mcp/sdk` requires it
	// for schema validation — and so exactly the kind of tree that can go
	// missing without anything this app imports naming it.
	'Opis\JsonSchema\Validator',
];

// Unlike the map reads above, nothing is returned here — this registers
// composer's autoloader, and doing that twice is never what we want.
require_once $autoload;

foreach (REQUIRED_CLASSES as $class) {
	if (!class_exists($class)) {
		$errors[] = sprintf('%s does not load from the package', $class);
	}
}

// The app's own code is autoloaded by Nextcloud from `lib/`, not by composer.
foreach (['lib', 'appinfo', 'js'] as $dir) {
	if (!is_dir($package . '/' . $dir)) {
		$errors[] = $dir . '/ is missing from the package';
	}
}

if ($errors !== []) {
	fwrite(STDERR, "error: the assembled package is not usable:\n\n");
	foreach ($errors as $error) {
		fwrite(STDERR, '  * ' . $error . "\n");
	}
	exit(1);
}

fwrite(STDOUT, "✓ package autoloads: composer targets present, vendored classes resolve\n");
