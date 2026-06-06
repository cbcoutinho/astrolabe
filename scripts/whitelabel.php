<?php

declare(strict_types=1);

/**
 * Apply whitelabel branding overrides to a staged Astrolabe build.
 *
 * Display-only rebrand: rewrites the user-facing fields of appinfo/info.xml
 * (name, summary, description, navigation label) and swaps the app icons.
 * The app id stays `astrolabe`, so routes, the translation domain and config
 * keys are untouched — the package still installs into the `astrolabe`
 * directory and coexists with nothing.
 *
 * This operates on a *staged copy* (the assembled build dir), never on the
 * source tree, so a build leaves the working tree clean.
 *
 * Usage:
 *   php scripts/whitelabel.php --target=<staged-dir> --config=<branding.json> [--project-root=<dir>]
 *
 * Icon paths in branding.json are resolved relative to --project-root
 * (defaults to the current working directory).
 */

$options = getopt('', ['target:', 'config:', 'project-root::']);

$target = $options['target'] ?? null;
$configPath = $options['config'] ?? null;
$projectRoot = $options['project-root'] ?? getcwd();

if (!is_string($target) || !is_string($configPath)) {
	fwrite(STDERR, "Usage: php scripts/whitelabel.php --target=<dir> --config=<branding.json> [--project-root=<dir>]\n");
	exit(2);
}

$infoPath = rtrim($target, '/') . '/appinfo/info.xml';
if (!is_file($infoPath)) {
	fwrite(STDERR, "Error: info.xml not found in staged build at $infoPath\n");
	exit(1);
}

if (!is_file($configPath)) {
	fwrite(STDERR, "Error: branding config not found at $configPath\n");
	exit(1);
}

$config = json_decode((string)file_get_contents($configPath), true);
if (!is_array($config)) {
	fwrite(STDERR, "Error: $configPath is not valid JSON\n");
	exit(1);
}

$dom = new DOMDocument();
$dom->preserveWhiteSpace = true;
$dom->formatOutput = false;
if ($dom->load($infoPath) === false) {
	fwrite(STDERR, "Error: could not parse $infoPath\n");
	exit(1);
}

$xpath = new DOMXPath($dom);

/**
 * Set the text content of the first node matching an XPath expression. CDATA
 * is used so values containing markdown/markup survive intact. No-op when the
 * value is null/empty (keep the stock value) or the node is missing.
 */
$setNode = static function (string $expr, ?string $value) use ($xpath, $dom): bool {
	if ($value === null || $value === '') {
		return false;
	}
	$node = $xpath->query($expr)->item(0);
	if (!$node instanceof DOMElement) {
		return false;
	}
	while ($node->firstChild !== null) {
		$node->removeChild($node->firstChild);
	}
	$node->appendChild($dom->createCDATASection($value));
	return true;
};

$applied = [];
if ($setNode('/info/name', isset($config['name']) ? (string)$config['name'] : null)) {
	$applied[] = 'name';
}
if ($setNode('/info/summary', isset($config['summary']) ? (string)$config['summary'] : null)) {
	$applied[] = 'summary';
}
if ($setNode('/info/description', isset($config['description']) && $config['description'] !== null ? (string)$config['description'] : null)) {
	$applied[] = 'description';
}
if ($setNode('/info/navigations/navigation/name', isset($config['navigationName']) ? (string)$config['navigationName'] : null)) {
	$applied[] = 'navigationName';
}

if ($dom->save($infoPath) === false) {
	fwrite(STDERR, "Error: could not write $infoPath\n");
	exit(1);
}

// Swap icons. Sources are resolved relative to the project root; copy onto the
// staged icon files in place (info.xml still references app.svg / app-dark.svg).
$icons = $config['icons'] ?? [];
$iconMap = [
	'light' => 'img/app.svg',
	'dark' => 'img/app-dark.svg',
];
foreach ($iconMap as $key => $dest) {
	if (!isset($icons[$key]) || $icons[$key] === null || $icons[$key] === '') {
		continue;
	}
	$src = rtrim((string)$projectRoot, '/') . '/' . ltrim((string)$icons[$key], '/');
	$destPath = rtrim($target, '/') . '/' . $dest;
	if (realpath($src) === realpath($destPath)) {
		// Default config points at the stock icon already in the build — no-op.
		continue;
	}
	if (!is_file($src)) {
		fwrite(STDERR, "Error: icon source not found: $src\n");
		exit(1);
	}
	if (copy($src, $destPath) === false) {
		fwrite(STDERR, "Error: could not copy $src -> $destPath\n");
		exit(1);
	}
	$applied[] = "icon:$key";
}

echo 'Whitelabel branding applied (' . ($applied === [] ? 'stock defaults, no overrides' : implode(', ', $applied)) . ")\n";
