<?php

declare(strict_types=1);

namespace OCA\Astrolabe\TaskProcessing;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Db\AgentConversationMapper;
use OCA\Astrolabe\Service\Assistant\AgentException;
use OCA\Astrolabe\Service\Assistant\AgentLoop;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\ContextAgentInteraction;
use Psr\Log\LoggerInterface;

/**
 * Backs the Assistant's "Chat with AI" with Astrolabe's own agent.
 *
 * `core:contextagent:interaction` is a **core** task type, and Assistant decides
 * it has an agent purely by asking whether that task type has a provider
 * (`ChatService::isContextAgentAvailable()`). Registering here is therefore
 * enough to take over the chat — no AppAPI, no ExApp, and no `context_chat`.
 *
 * If Nextcloud's own `context_agent` is also installed, both claim the task type
 * and the admin chooses between them in the AI settings via
 * `getPreferredProvider()`. Registration is gated on an admin opt-in for exactly
 * that reason: silently rewiring every chat on install would be rude.
 *
 * **Read-only.** The MCP token is minted with read scopes, so `tools/list`
 * offers nothing that writes. `actions` is therefore always empty and
 * `confirmation` is ignored — there is nothing to confirm. Granting write scopes
 * without building that round-trip would let the model change user data unasked.
 *
 * @psalm-suppress UnusedClass — registered as a TaskProcessing provider.
 */
final class ContextAgentProvider implements ISynchronousProvider {
	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private AgentLoop $agentLoop,
		private AgentConversationMapper $conversations,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getId(): string {
		return Application::APP_ID . ':contextagent';
	}

	#[\Override]
	public function getName(): string {
		return 'Astrolabe';
	}

	#[\Override]
	public function getTaskTypeId(): string {
		return ContextAgentInteraction::ID;
	}

	#[\Override]
	public function getExpectedRuntime(): int {
		// A few model round-trips plus tool calls. Only an estimate Nextcloud
		// shows in the UI; the real ceiling is the loop's own budget.
		return 60;
	}

	/**
	 * Declares `sources` on top of the task type's own output shape.
	 *
	 * Assistant persists `$taskOutput['sources']` and renders it in the
	 * "Information sources & actions" popover, and
	 * `Manager::setTaskResult()` validates against the task type's shape *plus*
	 * the provider's optional shape — so this is what makes citations survive.
	 *
	 * @return ShapeDescriptor[]
	 */
	#[\Override]
	public function getOptionalOutputShape(): array {
		return [
			'sources' => new ShapeDescriptor(
				'Sources',
				'Tools and documents the assistant consulted.',
				EShapeType::ListOfTexts,
			),
		];
	}

	/**
	 * Declaring `memories` is what makes Assistant send it.
	 *
	 * ChatService inspects the provider's optional input shape and, only if this
	 * key is present, passes summaries of the user's earlier chats
	 * (SessionSummaryService::getMemories). It is the sanctioned way for the
	 * Assistant to hand context to a provider, so it is worth accepting rather
	 * than reconstructing that context ourselves.
	 *
	 * @return ShapeDescriptor[]
	 */
	#[\Override]
	public function getOptionalInputShape(): array {
		return [
			'memories' => new ShapeDescriptor(
				'Memories',
				'What the Assistant recalls about this user from earlier chats.',
				EShapeType::ListOfTexts,
			),
		];
	}

	/**
	 * @return array<string, numeric|string>
	 */
	#[\Override]
	public function getOptionalInputShapeDefaults(): array {
		return [];
	}

	/**
	 * @return array<array-key, ShapeEnumValue[]>
	 */
	#[\Override]
	public function getOptionalInputShapeEnumValues(): array {
		return [];
	}

	/**
	 * @return array<array-key, ShapeEnumValue[]>
	 */
	#[\Override]
	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	/**
	 * @return array<string, numeric|string>
	 */
	#[\Override]
	public function getInputShapeDefaults(): array {
		return [];
	}

	/**
	 * @return array<array-key, ShapeEnumValue[]>
	 */
	#[\Override]
	public function getInputShapeEnumValues(): array {
		return [];
	}

	/**
	 * @return array<array-key, ShapeEnumValue[]>
	 */
	#[\Override]
	public function getOutputShapeEnumValues(): array {
		return [];
	}

	/**
	 * Flatten Assistant's memories into a single block, if it sent any.
	 *
	 * @param array<string, mixed> $input
	 */
	private function memories(array $input): string {
		/** @var mixed $memories */
		$memories = $input['memories'] ?? null;
		if (!is_array($memories)) {
			return '';
		}

		$lines = [];
		/** @var mixed $memory */
		foreach ($memories as $memory) {
			if (is_string($memory) && trim($memory) !== '') {
				$lines[] = $memory;
			}
		}

		return $lines === [] ? '' : implode("\n\n", $lines);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, list<string>|string>
	 * @throws ProcessingException
	 */
	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		if ($userId === null) {
			// Every tool call is scoped to a user's token; there is no sensible
			// "nobody" to run as.
			throw new ProcessingException('The assistant agent requires a signed-in user');
		}

		/** @var mixed $rawPrompt */
		$rawPrompt = $input['input'] ?? '';
		$prompt = is_string($rawPrompt) ? trim($rawPrompt) : '';
		if ($prompt === '') {
			throw new ProcessingException('The message was empty');
		}

		/** @var mixed $rawToken */
		$rawToken = $input['conversation_token'] ?? '';
		$token = is_string($rawToken) ? $rawToken : '';

		$conversation = $this->conversations->findForUser($token, $userId)
			?? $this->conversations->start($userId);

		$reportProgress(0.1);

		try {
			$result = $this->agentLoop->run(
				$userId,
				$prompt,
				$conversation->getDecodedHistory(),
				$this->memories($input),
			);
		} catch (AgentException $e) {
			$this->logger->warning('Astrolabe agent turn failed', [
				'exception' => $e,
				'app' => Application::APP_ID,
			]);
			throw new ProcessingException($e->getMessage(), 0, $e);
		}

		$reportProgress(0.9);

		$conversation->setDecodedHistory($result['history']);
		$conversation->setUpdatedAt(time());
		$this->conversations->update($conversation);

		return [
			'output' => $result['output'],
			// Handing the same token back keeps the next turn on this history.
			'conversation_token' => $conversation->getToken(),
			// Always empty while the agent is read-only: nothing it can do needs
			// the user's confirmation.
			'actions' => '',
			'sources' => $result['sources'],
		];
	}
}
