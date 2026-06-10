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
 *   PACT_PROVIDER_VERSION   provider version (git SHA) for published results
 *   PACT_PROVIDER_BRANCH    provider branch (default "master")
 *   PACT_PUBLISH_RESULTS    "true" to publish verification results
 */
final class CredentialsPactVerifyTest extends TestCase {
	public function testHonoursPublishedConsumerPacts(): void {
		$providerUrl = getenv('PACT_PROVIDER_URL') ?: '';
		$brokerUrl = getenv('PACT_BROKER') ?: '';
		if ($providerUrl === '' || $brokerUrl === '') {
			$this->markTestSkipped(
				'Provider verification needs PACT_PROVIDER_URL and PACT_BROKER. Skipped outside CI.'
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
			->setUsername(getenv('PACT_USERNAME') ?: '')
			->setPassword(getenv('PACT_PASSWORD') ?: '')
			->setEnablePending(true)
			->setProviderBranch(getenv('PACT_PROVIDER_BRANCH') ?: 'master')
			->setConsumerVersionSelectors($selectors);

		if (getenv('PACT_PUBLISH_RESULTS') === 'true') {
			$publish = new PublishOptions();
			$publish->setProviderVersion(getenv('PACT_PROVIDER_VERSION') ?: 'dev');
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
