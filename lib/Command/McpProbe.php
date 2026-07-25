<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Command;

use Mcp\Schema\Content\TextContent;
use OCA\Astrolabe\Service\Mcp\McpClientFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Inspect the MCP connection as a given user: what tools they can see, and what
 * a tool actually returns.
 *
 * The scope filtering that makes this integration safe happens server-side and
 * per token, so "which tools does this user get" is not answerable from reading
 * Astrolabe's code — it has to be asked. Likewise, whether a model can summarize
 * a whole document by fetching it through a tool depends on the shape of what
 * that tool returns, which is equally unanswerable from here.
 *
 * @psalm-suppress UnusedClass — registered as an occ command.
 */
final class McpProbe extends Command {
	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private McpClientFactory $clientFactory,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('astrolabe:mcp-probe')
			->setDescription('Inspect the MCP tool catalogue and tool output for a user')
			->addArgument('user', InputArgument::REQUIRED, 'User to connect as')
			->addOption('scopes', null, InputOption::VALUE_REQUIRED, 'Extra OAuth scopes', '')
			->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Only list tools whose name contains this', '')
			->addOption('call', null, InputOption::VALUE_REQUIRED, 'Tool to call')
			->addOption('args', null, InputOption::VALUE_REQUIRED, 'Tool arguments as JSON', '{}');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		/** @var string $userId */
		$userId = $input->getArgument('user');
		/** @var string $scopes */
		$scopes = $input->getOption('scopes');

		$client = $this->clientFactory->connect($userId, $scopes);

		try {
			$server = $client->getServerInfo();
			$output->writeln(sprintf(
				'<info>connected</info> to %s %s',
				$server?->name ?? '?',
				$server?->version ?? '?',
			));

			$tools = $client->listTools()->tools;
			$output->writeln(sprintf('<info>%d tool(s) visible to %s</info>', count($tools), $userId));

			/** @var string $filter */
			$filter = $input->getOption('filter');
			foreach ($tools as $tool) {
				if ($filter !== '' && !str_contains($tool->name, $filter)) {
					continue;
				}
				$hints = $tool->annotations;
				$output->writeln(sprintf(
					'  %-42s readOnly=%-5s destructive=%-5s %s',
					$tool->name,
					var_export($hints?->readOnlyHint ?? null, true),
					var_export($hints?->destructiveHint ?? null, true),
					substr((string)$tool->description, 0, 60),
				));
			}

			/** @var string|null $call */
			$call = $input->getOption('call');
			if ($call !== null && $call !== '') {
				/** @var string $rawArgs */
				$rawArgs = $input->getOption('args');
				/** @var array<string, mixed> $args */
				$args = json_decode($rawArgs, true, 512, JSON_THROW_ON_ERROR);

				$output->writeln(sprintf("\n<info>calling %s</info> %s", $call, $rawArgs));
				$result = $client->callTool($call, $args);

				$output->writeln('isError: ' . var_export($result->isError, true));
				foreach ($result->content as $i => $content) {
					$type = $content::class;
					$output->writeln(sprintf('content[%d] %s', $i, $type));
					if ($content instanceof TextContent) {
						/** @var string $text */
						$text = $content->text;
						$output->writeln(sprintf('  %d chars', strlen($text)));
						$output->writeln('  ' . str_replace("\n", "\n  ", substr($text, 0, 600)));
					} else {
						// Anything non-text is the interesting case for feeding a
						// document to a multimodal model, so dump its shape.
						$encoded = json_encode($content);
						$output->writeln('  ' . substr($encoded === false ? '' : $encoded, 0, 400));
					}
				}
			}
		} finally {
			$client->disconnect();
		}

		return 0;
	}
}
