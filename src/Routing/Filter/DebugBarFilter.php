<?php
/**
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace DebugKit\Routing\Filter;

use DebugKit\Panel\DebugPanel;
use DebugKit\Panel\PanelRegistry;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Event\EventManagerTrait;
use Cake\ORM\TableRegistry;
use Cake\Routing\DispatcherFilter;
use Cake\Routing\Router;
use Cake\Utility\String;

/**
 * Toolbar injector filter.
 *
 * This class loads all the panels into the registry
 * and binds the correct events into the provided event
 * manager
 */
class DebugBarFilter extends DispatcherFilter {

	use EventManagerTrait;

/**
 * The panel registry.
 *
 * @var \DebugKit\Panel\PanelRegistry
 */
	protected $_registry;

/**
 * Default configuration.
 *
 * @var array
 */
	protected $_defaultConfig = [
		// Attempt to execute last.
		'priority' => 9999,
		'panels' => [
			'DebugKit.Session',
			'DebugKit.Request',
			'DebugKit.SqlLog',
			'DebugKit.Timer',
			'DebugKit.Log',
			'DebugKit.Variables',
			'DebugKit.Environment',
			'DebugKit.Include'
		],
		'forceEnable' => false,
	];

/**
 * Constructor
 *
 * @param \Cake\Event\EventManager $events The event manager to use.
 * @param array $config The configuration data for DebugKit.
 */
	public function __construct(EventManager $events, array $config) {
		$this->eventManager($events);
		$this->config($config);
		$this->_registry = new PanelRegistry($events);
	}

/**
 * Check whether or not debug kit is enabled.
 *
 * @return bool
 */
	public function isEnabled() {
		$enabled = (bool)Configure::read('debug');
		if ($enabled) {
			return true;
		}
		return (bool)$this->config('forceEnable');
	}

/**
 * Get the list of loaded panels
 *
 * @return array
 */
	public function loadedPanels() {
		return $this->_registry->loaded();
	}

/**
 * Get the list of loaded panels
 *
 * @return DebugKit\Panel\DebugPanel|null The panel or null.
 */
	public function panel($name) {
		return $this->_registry->{$name};
	}

/**
 * Do the required setup work.
 *
 * - Build panels.
 * - Connect events
 *
 * @return void
 */
	public function setup() {
		foreach ($this->config('panels') as $panel) {
			$this->_registry->load($panel);
		}
	}

/**
 * Save the toolbar data.
 *
 * @param \Cake\Network\Request $request The request to save panel data for.
 * @return void
 */
	public function afterDispatch(Event $event) {
		$request = $event->data['request'];
		// Skip debugkit requests.
		if ($request->param('plugin') === 'DebugKit') {
			return;
		}
		$response = $event->data['response'];

		$data = [
			'url' => $request->url,
			'content_type' => $response->type(),
			'status_code' => $response->statusCode(),
			'requested_at' => $request->env('REQUEST_TIME'),
			'panels' => []
		];
		$requests = TableRegistry::get('DebugKit.Requests');
		$row = $requests->newEntity($data);
		$row->isNew(true);

		foreach ($this->_registry->loaded() as $name) {
			$panel = $this->_registry->{$name};
			$row->panels[] = $requests->Panels->newEntity([
				'panel' => $name,
				'element' => $panel->elementName(),
				'title' => $panel->title(),
				'content' => serialize($panel->data())
			]);
		}
		$row = $requests->save($row);

		$this->_injectScripts($row->id, $response);
	}

/**
 * Injects the JS to build the toolbar.
 *
 * The toolbar will only be injected if the response's content type
 * contains HTML and there is a </body> tag.
 *
 * @param string $id ID to fetch data from.
 * @param \Cake\Network\Response $response The response to augment.
 * @return void
 */
	protected function _injectScripts($id, $response) {
		if (strpos($response->type(), 'html') === false) {
			return;
		}
		$body = $response->body();
		$pos = strrpos($body, '</body>');
		if ($pos === false) {
			return;
		}
		$url = Router::url('/', true);
		$script = "<script>var __debug_kit_id = '${id}', __debug_kit_base_url = '${url}';</script>";
		$script .= '<script src="' . Router::url('/debug_kit/js/toolbar.js') . '"></script>';
		$body = substr($body, 0, $pos) . $script . substr($body, $pos);
		$response->body($body);
	}

}
