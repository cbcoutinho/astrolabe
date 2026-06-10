<?php

declare(strict_types=1);

/**
 * Bootstrap for Pact contract tests.
 *
 * Unlike the unit bootstrap, these tests drive the bundled Rust verifier (and,
 * for consumer tests, a real HTTP client) rather than mocked OCP interfaces, so
 * only the composer autoloader is required.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
