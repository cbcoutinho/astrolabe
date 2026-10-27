<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Contract\Provider;

use GuzzleHttp\Psr7\Uri;
use PhpPact\Standalone\ProviderVerifier\Model\Config\PublishOptions;
use PhpPact\Standalone\ProviderVerifier\Model\ConsumerVersionSelectors;
use PhpPact\Standalone\ProviderVerifier\Model\Selector\Selector;
use PhpPact\Standalone\ProviderVerifier\Model\Source\Broker;
use PhpPact\Standalone\ProviderVerifier\Model\VerifierConfig;
use PhpPact\Standalone\ProviderVerifier\Verifier;
use PHPUnit\Framework\TestCase;

/**
 * Provider verification: nextcloud-mcp-server -> astrolabe credentials API.
 *
 * Replays the pacts the MCP server publishes for astrolabe (consumer
 * ``nextcloud-mcp-server``, provider ``astrolabe``) against a RUNNING Nextcloud
 * with the astrolabe app installed, failing if a response no longer matches the
 * contract. See ADR-029 (nextcloud-mcp-server) for the architecture.
 *
 * This is an INTEGRATION test, not a unit test:
 *   - requires ``ext-ffi`` (pact-php bundles the Rust verifier),
 *   - requires a running provider (Nextcloud + astrolabe),
 *   - requires a state-change endpoint that seeds the provider states the
 *     pacts declare (see tests/contract/README.md for the exact contract).
 *
 * It is environment-gated and skips unless both a provider URL and the broker
 * are configured, so it is a no-op in the unit matrix and local unit runs.
 *
 * Required environment:
 *   PACT_PROVIDER_URL  base URL of the running Nextcloud (e.g. http://localhost:8080)
 *   PACT_BROKER        broker base URL
 *   PACT_USERNAME      broker basic-auth user
 *   PACT_PASSWORD      broker basic-auth password
 * Optional:
 *   PACT_PROVIDER_AUTH      "user:app-password" of a Nextcloud admin, sent as
 *                           Basic auth in place of the pacts' placeholder creds
 *   PACT_PROVIDER_REQUIRED  "true" to fail, not skip, without a provider (CI)
 *   PACT_PROVIDER_VERSION   provider version (git SHA) for published results
 *   PACT_PROVIDER_BRANCH    provider branch (default "main")
 *   PACT_PUBLISH_RESULTS    "true" to publish verification results
 */
final class CredentialsPactVerifyTest extends TestCase {
	public function testHonoursPublishedConsumerPacts(): void {
		$providerUrl = getenv('PACT_PROVIDER_URL') ?: '';
		$brokerUrl = getenv('PACT_BROKER') ?: '';
		$brokerUser = getenv('PACT_USERNAME') ?: '';
		$brokerPass = getenv('PACT_PASSWORD') ?: '';
		$providerAuth = getenv('PACT_PROVIDER_AUTH') ?: '';
		// pact.yml stands up a provider, so anything missing there is a broken
		// job, not a reason to skip and report green.
		$required = ['PACT_PROVIDER_URL' => $providerUrl, 'PACT_PROVIDER_AUTH' => $providerAuth,
			'PACT_BROKER' => $brokerUrl, 'PACT_USERNAME' => $brokerUser, 'PACT_PASSWORD' => $brokerPass];
		$missing = array_keys(array_filter($required, fn (string $v): bool => $v === ''));
		if (getenv('PACT_PROVIDER_REQUIRED') === 'true' && $missing !== []) {
			$this->fail('PACT_PROVIDER_REQUIRED is set but these are not: ' . implode(', ', $missing));
		}
		// Require the broker creds too, so a broker-set-but-creds-missing CI
		// skips cleanly rather than silently attempting unauthenticated access.
		if ($providerUrl === '' || $brokerUrl === '' || $brokerUser === '' || $brokerPass === '') {
			$this->markTestSkipped(
				'Provider verification needs PACT_PROVIDER_URL and PACT_BROKER '
				. '(+ PACT_USERNAME/PACT_PASSWORD). Skipped outside CI.'
			);
		}

		$providerUri = new Uri($providerUrl);
		$defaultPort = $providerUri->getScheme() === 'https' ? 443 : 80;

		$config = new VerifierConfig();
		$config->getProviderInfo()
			->setName('astrolabe')
			->setScheme($providerUri->getScheme())
			->setHost($providerUri->getHost())
			->setPort($providerUri->getPort() ?? $defaultPort);

		// The pacts carry the consumer's placeholder credentials (a dummy
		// Bearer token, or none for OCS). Replace them with a real app password
		// ("user:app-password"): the credentials endpoint is admin-only, and an
		// app password also exempts the request from the CSRF check.
		if ($providerAuth !== '') {
			$config->getCustomHeaders()->addHeader('Authorization', 'Basic ' . base64_encode($providerAuth));
		}

		// Pact POSTs {consumer, state, action, params} here before/after each
		// interaction to set up the declared provider state. The endpoint lives
		// in the astrolabe app, guarded behind a system-config flag — see
		// tests/contract/README.md.
		$config->getProviderState()
			->setStateChangeUrl(new Uri(
				rtrim($providerUrl, '/') . '/apps/astrolabe/api/v1/test/pact-provider-state'
			))
			->setStateChangeTeardown(true)
			->setStateChangeAsBody(true);

		// Verify the consumer's main branch and whatever is deployed/released.
		$selectors = (new ConsumerVersionSelectors())
			->addSelector(new Selector(mainBranch: true))
			->addSelector(new Selector(deployedOrReleased: true));

		$broker = new Broker();
		$broker
			->setUrl(new Uri($brokerUrl))
			->setUsername($brokerUser)
			->setPassword($brokerPass)
			->setEnablePending(true)
			->setProviderBranch(getenv('PACT_PROVIDER_BRANCH') ?: 'main')
			->setConsumerVersionSelectors($selectors);

		if (getenv('PACT_PUBLISH_RESULTS') === 'true') {
			// Fail loudly rather than publish verification results under a
			// meaningless version: a bogus version corrupts the broker's
			// can-i-deploy records.
			$providerVersion = getenv('PACT_PROVIDER_VERSION') ?: '';
			if ($providerVersion === '') {
				throw new \RuntimeException(
					'PACT_PROVIDER_VERSION must be set when PACT_PUBLISH_RESULTS=true'
				);
			}
			$publish = new PublishOptions();
			$publish->setProviderVersion($providerVersion);
			if ($branch = getenv('PACT_PROVIDER_BRANCH')) {
				$publish->setProviderBranch($branch);
			}
			$config->setPublishOptions($publish);
		}

		$verifier = new Verifier($config);
		$verifier->addBroker($broker);

		// Raises / returns false (failing the test) if any interaction mismatches.
		$this->assertTrue($verifier->verify(), 'Pact provider verification failed');
	}
}
