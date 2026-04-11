<?php

declare(strict_types=1);

/**
 * Stubs for internal OC\ classes not available in nextcloud/ocp.
 *
 * These are needed so PHPUnit can create mocks for controllers that
 * depend on internal Nextcloud interfaces.
 */

namespace OC\Authentication\Token {
	if (!interface_exists(IProvider::class)) {
		interface IProvider {
		}
	}
	if (!interface_exists(IToken::class)) {
		interface IToken {
		}
	}
}
