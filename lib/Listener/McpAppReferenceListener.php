<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Listener;

use OCA\Astrolabe\AppInfo\Application;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads the astrolabe_mcp_app reference widget wherever rich references render
 * (Assistant chat, Talk, Text, …). Exploratory POC.
 *
 * @template-implements IEventListener<Event>
 */
final class McpAppReferenceListener implements IEventListener {
	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof RenderReferenceEvent) {
			return;
		}
		Util::addScript(Application::APP_ID, Application::APP_ID . '-reference');
		// Its own CSS only: astrolabe-main.css carries global layout rules that
		// break the host app (seen in Files).
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-reference');
	}
}
