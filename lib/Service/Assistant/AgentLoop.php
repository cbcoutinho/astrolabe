<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Assistant;

use Mcp\Client;
use Mcp\Schema\Content\TextContent;
use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Service\Mcp\McpClientFactory;
use OCA\Astrolabe\Service\Mcp\McpUnavailableException;
use OCA\Astrolabe\Settings\Admin as AdminSettings;
use OCP\IAppConfig;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToTextChatWithTools;
use Psr\Log\LoggerInterface;

/**
 * Runs one agent turn: model, tools, model, until it stops asking for tools.
 *
 * The model is Nextcloud's (`core:text2text:chatwithtools`) and the tools are
 * the MCP server's, so this class owns neither — it owns the loop between them,
 * and the budget that stops it running away.
 *
 * **Read-only by construction.** The token is minted with read scopes only, and
 * the MCP server filters `tools/list` by those scopes, so the model is never
 * offered a tool that writes. That is what lets every call execute immediately
 * with no confirmation round-trip: there is nothing to confirm. Adding a write
 * scope to the admin setting without building that round-trip would quietly turn
 * this into an agent that changes user data unasked.
 */
final class AgentLoop {
	/**
	 * Cap on characters of tool output fed back per call. A directory listing or
	 * a base64 blob can otherwise fill the context window in a single round and
	 * push out the conversation it was meant to inform.
	 */
	private const MAX_TOOL_RESULT_CHARS = 8000;

	private const SYSTEM_PROMPT = <<<'PROMPT'
		You are the assistant for a Nextcloud user, with tools that search and read
		their own content. Prefer searching before answering: you have no reliable
		memory of their files.

		Cite what you used. When a fact comes from a document, name that document in
		your answer. If the tools return nothing relevant, say so plainly instead of
		answering from general knowledge — the user is asking about their content,
		not the world.

		Your tools are read-only. If asked to create, change or delete something,
		explain that you can only read for now.
		PROMPT;

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private IManager $taskProcessing,
		private McpClientFactory $clientFactory,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param list<array{role: string, content: string}> $history
	 * @return array{output: string, sources: list<string>, history: list<array{role: string, content: string}>}
	 * @throws AgentException
	 */
	public function run(string $userId, string $prompt, array $history): array {
		try {
			$client = $this->clientFactory->connect($userId, $this->scopes());
		} catch (McpUnavailableException $e) {
			throw new AgentException($e->getMessage(), $e);
		}

		try {
			return $this->drive($client, $userId, $prompt, $history);
		} finally {
			// The server holds a session per connection; leaving it open leaks one
			// per turn.
			try {
				$client->disconnect();
			} catch (\Throwable $e) {
				$this->logger->info('MCP disconnect failed after an agent turn', [
					'exception' => $e,
					'app' => Application::APP_ID,
				]);
			}
		}
	}

	/**
	 * @param list<array{role: string, content: string}> $history
	 * @return array{output: string, sources: list<string>, history: list<array{role: string, content: string}>}
	 * @throws AgentException
	 */
	private function drive(Client $client, string $userId, string $prompt, array $history): array {
		$tools = $this->toolCatalogue($client);
		if ($tools === '') {
			throw new AgentException(
				'No tools are available to the assistant. Check that this user is provisioned '
				. 'with the MCP server and that the configured scopes grant at least one tool.',
			);
		}

		$deadline = time() + $this->timeout();
		$maxIterations = $this->maxIterations();

		$toolResults = '';
		$sources = [];
		$output = '';
		$truncated = true;
		$ranOutOfTime = false;

		for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
			if (time() >= $deadline) {
				$ranOutOfTime = true;
				break;
			}

			$result = $this->askModel($userId, $this->prompt($prompt, $toolResults), $history, $tools);
			$output = $result['output'];
			$calls = $result['tool_calls'];

			if ($calls === []) {
				$truncated = false;
				break;
			}

			// Results are folded into the next prompt rather than sent through the
			// task type's `tool_message` slot. That slot looks like the right home,
			// but integration_openai appends it with role=tool directly after the
			// user message (OpenAiAPIService:584-590) without ever emitting the
			// assistant turn that carried the tool_calls — and `history` is placed
			// *before* the user message, so the missing turn cannot be injected
			// either. Mistral rejects the result outright:
			// "Unexpected role 'tool' after role 'user'".
			//
			// Restating the observations as user text keeps the content in front of
			// the model and works against any provider, at the cost of the formal
			// call/result linkage. Revisit if that provider learns to emit the
			// assistant tool-call turn.
			$observations = [];
			foreach ($calls as $call) {
				$observations[] = sprintf(
					"Result of %s:\n%s",
					$call['name'],
					$this->invoke($client, $call, $sources),
				);
			}
			$toolResults = implode("\n\n", $observations);
		}

		if ($truncated && trim($output) === '') {
			// Say which limit was hit: one is the user's cue to narrow the
			// question, the other is the admin's cue to raise a setting.
			$output = $ranOutOfTime
				? 'I ran out of time working on that. Please try a narrower question.'
				: 'I kept looking things up without reaching an answer. Please try a more specific question.';
		} elseif ($truncated) {
			// Say it rather than presenting a partial answer as a complete one.
			$output .= "\n\n_(I stopped before finishing — this may be incomplete.)_";
		}

		$history[] = ['role' => 'human', 'content' => $prompt];
		$history[] = ['role' => 'assistant', 'content' => $output];

		return [
			'output' => $output,
			'sources' => array_values(array_unique($sources)),
			'history' => $history,
		];
	}

	/**
	 * One round-trip through the tool-calling model.
	 *
	 * @param list<array{role: string, content: string}> $history
	 * @return array{output: string, tool_calls: list<array{id: string, name: string, arguments: array<string, mixed>}>}
	 * @throws AgentException
	 */
	private function askModel(
		string $userId,
		string $prompt,
		array $history,
		string $toolsJson,
	): array {
		$task = new Task(
			TextToTextChatWithTools::ID,
			[
				'system_prompt' => self::SYSTEM_PROMPT,
				'input' => $prompt,
				'tool_message' => '',
				'history' => array_map(
					static fn (array $turn): string => json_encode($turn, JSON_THROW_ON_ERROR),
					$history,
				),
				'tools' => $toolsJson,
			],
			Application::APP_ID . ':agent',
			$userId,
		);

		try {
			$completed = $this->taskProcessing->runTask($task);
		} catch (\Throwable $e) {
			throw new AgentException('The assistant model could not be reached: ' . $e->getMessage(), $e);
		}

		if ($completed->getStatus() !== Task::STATUS_SUCCESSFUL) {
			throw new AgentException('The assistant model failed: ' . ($completed->getErrorMessage() ?? 'unknown error'));
		}

		$taskOutput = $completed->getOutput();
		/** @var mixed $text */
		$text = $taskOutput['output'] ?? '';
		/** @var mixed $rawCalls */
		$rawCalls = $taskOutput['tool_calls'] ?? '';

		return [
			'output' => is_string($text) ? $text : '',
			'tool_calls' => $this->parseToolCalls(is_string($rawCalls) ? $rawCalls : ''),
		];
	}

	/**
	 * Restate tool output as part of the user turn.
	 *
	 * The original question is repeated so the model keeps sight of what it is
	 * answering after several rounds of observations.
	 */
	private function prompt(string $question, string $toolResults): string {
		if ($toolResults === '') {
			return $question;
		}

		return $question
			. "\n\nHere is what your tools returned. Answer from this if you can, "
			. "and only call another tool if something essential is still missing.\n\n"
			. $toolResults;
	}

	/**
	 * Tool calls arrive as a JSON string produced by a model, so nothing about
	 * their shape is guaranteed. Anything unrecognisable is dropped rather than
	 * failing the turn — a malformed call should cost one observation, not the
	 * whole conversation.
	 *
	 * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
	 */
	private function parseToolCalls(string $raw): array {
		if (trim($raw) === '') {
			return [];
		}

		try {
			/** @var mixed $decoded */
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			$this->logger->info('Discarding unparsable tool_calls from the model', [
				'exception' => $e,
				'app' => Application::APP_ID,
			]);
			return [];
		}

		if (!is_array($decoded)) {
			return [];
		}
		// Accept both a bare list and the {"tool_calls": [...]} wrapper.
		/** @var mixed $list */
		$list = $decoded['tool_calls'] ?? $decoded;
		if (!is_array($list)) {
			return [];
		}

		$calls = [];
		/** @var mixed $entry */
		foreach ($list as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			/** @var mixed $name */
			$name = $entry['name'] ?? ($entry['function']['name'] ?? null);
			if (!is_string($name) || $name === '') {
				continue;
			}
			// integration_openai emits `args`; the OpenAI wire format uses
			// `arguments`, nested under `function` for some providers. Accept all
			// three — reading the wrong key silently calls every tool with no
			// arguments, which looks like the model asking bad questions.
			/** @var mixed $arguments */
			$arguments = $entry['args']
				?? $entry['arguments']
				?? ($entry['function']['arguments'] ?? []);
			if (is_string($arguments)) {
				// Some providers nest the arguments as a JSON string.
				try {
					/** @var mixed $arguments */
					$arguments = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
				} catch (\JsonException) {
					$arguments = [];
				}
			}
			/** @var array<string, mixed> $args */
			$args = is_array($arguments) ? $arguments : [];
			/** @var mixed $id */
			$id = $entry['id'] ?? '';
			$calls[] = [
				// The provider pairs each result with its call by id; a missing one
				// leaves the model unable to tell which answer belongs to which call.
				'id' => is_string($id) ? $id : '',
				'name' => $name,
				'arguments' => $args,
			];
		}
		return $calls;
	}

	/**
	 * Run one tool call and render its result for the model.
	 *
	 * A failing tool is reported back as an observation rather than thrown: the
	 * model can usually recover by trying a different query, and killing the turn
	 * over one bad call would be worse than letting it adapt.
	 *
	 * @param array{id: string, name: string, arguments: array<string, mixed>} $call
	 * @param list<string> $sources
	 */
	private function invoke(Client $client, array $call, array &$sources): string {
		try {
			$result = $client->callTool($call['name'], $call['arguments']);
		} catch (\Throwable $e) {
			$this->logger->info('Agent tool call failed', [
				'tool' => $call['name'],
				'exception' => $e,
				'app' => Application::APP_ID,
			]);
			return sprintf('This tool failed: %s', $e->getMessage());
		}

		$sources[] = $call['name'];

		$parts = [];
		foreach ($result->content as $content) {
			if ($content instanceof TextContent) {
				$parts[] = (string)$content->text;
			}
		}
		$text = trim(implode("\n", $parts));

		if ($text === '') {
			return 'This tool returned no results.';
		}
		if (strlen($text) > self::MAX_TOOL_RESULT_CHARS) {
			$text = substr($text, 0, self::MAX_TOOL_RESULT_CHARS)
				. "\n…(truncated; narrow the query for more detail)";
		}

		return $text;
	}

	/**
	 * The tool catalogue as the JSON the task type expects.
	 *
	 * Whatever the server returns is already narrowed to this user's scopes, so
	 * no filtering happens here — the boundary is the token, not this code.
	 */
	private function toolCatalogue(Client $client): string {
		try {
			$tools = $client->listTools()->tools;
		} catch (\Throwable $e) {
			throw new AgentException('Could not list the assistant\'s tools: ' . $e->getMessage(), $e);
		}

		$described = [];
		foreach ($tools as $tool) {
			$described[] = [
				'type' => 'function',
				'function' => [
					'name' => $tool->name,
					'description' => (string)$tool->description,
					'parameters' => $tool->inputSchema,
				],
			];
		}

		return $described === [] ? '' : json_encode($described, JSON_THROW_ON_ERROR);
	}

	private function scopes(): string {
		return $this->appConfig->getValueString(
			Application::APP_ID,
			AdminSettings::SETTING_AGENT_SCOPES,
			AdminSettings::DEFAULT_AGENT_SCOPES,
		);
	}

	private function maxIterations(): int {
		return max(1, $this->appConfig->getValueInt(
			Application::APP_ID,
			AdminSettings::SETTING_AGENT_MAX_ITERATIONS,
			AdminSettings::DEFAULT_AGENT_MAX_ITERATIONS,
		));
	}

	private function timeout(): int {
		return max(10, $this->appConfig->getValueInt(
			Application::APP_ID,
			AdminSettings::SETTING_AGENT_TIMEOUT,
			AdminSettings::DEFAULT_AGENT_TIMEOUT,
		));
	}
}
