<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Assistant;

/**
 * Reads the tool calls a model asked for out of its free-form `tool_calls` text.
 *
 * Pure, so the "never fails on model output" contract can be checked against
 * arbitrary input rather than a handful of remembered shapes.
 */
final class ToolCallParser {
	/**
	 * Tool calls arrive as a JSON string produced by a model, so nothing about
	 * their shape is guaranteed. Anything unrecognisable is dropped rather than
	 * failing the turn — a malformed call should cost one observation, not the
	 * whole conversation.
	 *
	 * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
	 * @throws \JsonException when the text is not JSON at all, so the caller can
	 *                        log what the model sent; nothing else is thrown
	 */
	public static function parse(string $raw): array {
		if (trim($raw) === '') {
			return [];
		}

		/** @var mixed $decoded */
		$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
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
}
