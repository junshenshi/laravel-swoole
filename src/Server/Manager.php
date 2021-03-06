<?php

namespace SwooleTW\Http\Server;

use Exception;
use Swoole\Table as SwooleTable;
use Swoole\Http\Server as HttpServer;
use Illuminate\Support\Facades\Facade;
use Illuminate\Contracts\Container\Container;
use Swoole\WebSocket\Server as WebSocketServer;
use SwooleTW\Http\Websocket\Websocket;
use SwooleTW\Http\Websocket\CanWebsocket;
use SwooleTW\Http\Websocket\Rooms\RoomContract;

class Manager
{
    use CanWebsocket;

    const MAC_OSX = 'Darwin';

    /**
     * @var \Swoole\Http\Server | \Swoole\Websocket\Server
     */
    protected $server;

    /**
     * Container.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $container;

    /**
     * @var \SwooleTW\Http\Server\Application
     */
    protected $application;

    /**
     * Laravel|Lumen Application.
     *
     * @var \Illuminate\Container\Container
     */
    protected $app;

    /**
     * @var \SwooleTW\Http\Server\Table
     */
    protected $table;

    /**
     * @var string
     */
    protected $framework;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * Server events.
     *
     * @var array
     */
    protected $events = [
        'start', 'shutDown', 'workerStart', 'workerStop', 'packet',
        'bufferFull', 'bufferEmpty', 'task', 'finish', 'pipeMessage',
        'workerError', 'managerStart', 'managerStop', 'request',
    ];

    /**
     * HTTP server manager constructor.
     *
     * @param \Illuminate\Contracts\Container\Container $container
     * @param string $framework
     * @param string $basePath
     */
    public function __construct(Container $container, $framework, $basePath = null)
    {
        $this->container = $container;
        $this->framework = $framework;
        $this->basePath = $basePath;

        $this->initialize();
    }

    /**
     * Run swoole server.
     */
    public function run()
    {
        $this->server->start();
    }

    /**
     * Stop swoole server.
     */
    public function stop()
    {
        $this->server->shutdown();
    }

    /**
     * Initialize.
     */
    protected function initialize()
    {
        $this->setProcessName('manager process');

        $this->createTables();
        $this->prepareWebsocket();
        $this->createSwooleServer();
        $this->configureSwooleServer();
        $this->setSwooleServerListeners();
    }

    /**
     * Prepare settings if websocket is enabled.
     */
    protected function createTables()
    {
        $this->table = new Table;
        $this->registerTables();
    }

    /**
     * Prepare settings if websocket is enabled.
     */
    protected function prepareWebsocket()
    {
        $isWebsocket = $this->container['config']->get('swoole_http.websocket.enabled');
        $formatter = $this->container['config']->get('swoole_websocket.formatter');

        if ($isWebsocket) {
            array_push($this->events, ...$this->wsEvents);
            $this->isWebsocket = true;
            $this->setFormatter(new $formatter);
            $this->setWebsocketHandler();
            $this->setWebsocketRoom();
        }
    }

    /**
     * Create swoole server.
     */
    protected function createSwooleServer()
    {
        $server = $this->isWebsocket ? WebsocketServer::class : HttpServer::class;
        $host = $this->container['config']->get('swoole_http.server.host');
        $port = $this->container['config']->get('swoole_http.server.port');

        $this->server = new $server($host, $port);
    }

    /**
     * Set swoole server configurations.
     */
    protected function configureSwooleServer()
    {
        $config = $this->container['config']->get('swoole_http.server.options');

        $this->server->set($config);
    }

    /**
     * Set swoole server listeners.
     */
    protected function setSwooleServerListeners()
    {
        foreach ($this->events as $event) {
            $listener = 'on' . ucfirst($event);

            if (method_exists($this, $listener)) {
                $this->server->on($event, [$this, $listener]);
            } else {
                $this->server->on($event, function () use ($event) {
                    $event = sprintf('swoole.%s', $event);

                    $this->container['events']->fire($event, func_get_args());
                });
            }
        }
    }

    /**
     * "onStart" listener.
     */
    public function onStart()
    {
        $this->setProcessName('master process');
        $this->createPidFile();

        $this->container['events']->fire('swoole.start', func_get_args());
    }

    /**
     * "onWorkerStart" listener.
     */
    public function onWorkerStart()
    {
        $this->clearCache();
        $this->setProcessName('worker process');

        $this->container['events']->fire('swoole.workerStart', func_get_args());

        // clear events instance in case of repeated listeners in worker process
        Facade::clearResolvedInstance('events');

        $this->createApplication();
        $this->setLaravelApp();
        $this->bindSwooleServer();
        $this->bindSwooleTable();

        if ($this->isWebsocket) {
            $this->bindRoom();
            $this->bindWebsocket();
        }
    }

    /**
     * "onRequest" listener.
     *
     * @param \Swoole\Http\Request $swooleRequest
     * @param \Swoole\Http\Response $swooleResponse
     */
    public function onRequest($swooleRequest, $swooleResponse)
    {
        $this->container['events']->fire('swoole.request');

        // Reset user-customized providers
        $this->getApplication()->resetProviders();
        $illuminateRequest = Request::make($swooleRequest)->toIlluminate();

        try {
            $illuminateResponse = $this->getApplication()->run($illuminateRequest);
            $response = Response::make($illuminateResponse, $swooleResponse);
            $response->send();
        } catch (Exception $e) {
            $this->logServerError($e);

            try {
                $swooleResponse->status(500);
                $swooleResponse->end('Oops! An unexpected error occurred.');
            } catch (Exception $e) {
                // Catch: zm_deactivate_swoole: Fatal error: Uncaught exception
                // 'ErrorException' with message 'swoole_http_response::status():
                // http client#2 is not exist.
            }
        }
    }

    /**
     * Set onTask listener.
     */
    public function onTask(HttpServer $server, $taskId, $fromId, $data)
    {
        $this->container['events']->fire('swoole.task', func_get_args());

        try {
            // push websocket message
            if ($this->isWebsocket
                && array_key_exists('action', $data)
                && $data['action'] === Websocket::PUSH_ACTION) {
                $this->pushMessage($server, $data['data'] ?? []);
            }
        } catch (Exception $e) {
            $this->logServerError($e);
        }
    }

    /**
     * Set onFinish listener.
     */
    public function onFinish(HttpServer $server, $taskId, $data)
    {
        // task worker callback
    }

    /**
     * Set onShutdown listener.
     */
    public function onShutdown()
    {
        $this->removePidFile();

        $this->container['events']->fire('swoole.shutdown', func_get_args());
    }

    /**
     * Create application.
     */
    protected function createApplication()
    {
        return $this->application = Application::make($this->framework, $this->basePath);
    }

    /**
     * Get application.
     *
     * @return \SwooleTW\Http\Server\Application
     */
    protected function getApplication()
    {
        if (! $this->application instanceof Application) {
            $this->createApplication();
        }

        return $this->application;
    }

    /**
     * Set Laravel app.
     */
    protected function setLaravelApp()
    {
        $this->app = $this->getApplication()->getApplication();
    }

    /**
     * Bind swoole server to Laravel app container.
     */
    protected function bindSwooleServer()
    {
        $this->app->singleton('swoole.server', function () {
            return $this->server;
        });
    }

    /**
     * Bind swoole table to Laravel app container.
     */
    protected function bindSwooleTable()
    {
        $this->app->singleton('swoole.table', function () {
            return $this->table;
        });
    }

    /**
     * Gets pid file path.
     *
     * @return string
     */
    protected function getPidFile()
    {
        return $this->container['config']->get('swoole_http.server.options.pid_file');
    }

    /**
     * Create pid file.
     */
    protected function createPidFile()
    {
        $pidFile = $this->getPidFile();
        $pid = $this->server->master_pid;

        file_put_contents($pidFile, $pid);
    }

    /**
     * Remove pid file.
     */
    protected function removePidFile()
    {
        unlink($this->getPidFile());
    }

    /**
     * Clear APC or OPCache.
     */
    protected function clearCache()
    {
        if (function_exists('apc_clear_cache')) {
            apc_clear_cache();
        }

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * Set process name.
     *
     * @param $process
     */
    protected function setProcessName($process)
    {
        if (PHP_OS === static::MAC_OSX) {
            return;
        }
        $serverName = 'swoole_http_server';
        $appName = $this->container['config']->get('app.name', 'Laravel');

        $name = sprintf('%s: %s for %s', $serverName, $process, $appName);

        swoole_set_process_name($name);
    }

    /**
     * Log server error.
     *
     * @param Exception
     */
    protected function logServerError(Exception $e)
    {
        $logFile = $this->container['config']->get('swoole_http.server.options.log_file');

        try {
            $output = fopen($logFile ,'w');
        } catch (Exception $e) {
            $output = STDOUT;
        }

        $prefix = sprintf("[%s #%d *%d]\tERROR\t", date('Y-m-d H:i:s'), $this->server->master_pid, $this->server->worker_id);

        fwrite($output, sprintf('%s%s(%d): %s', $prefix, $e->getFile(), $e->getLine(), $e->getMessage()) . PHP_EOL);
    }

    /**
     * Register user-defined swoole tables.
     */
    protected function registerTables()
    {
        $tables = $this->container['config']->get('swoole_http.tables') ?? [];

        foreach ($tables as $key => $value) {
            $table = new SwooleTable($value['size']);
            $columns = $value['columns'] ?? [];
            foreach ($columns as $column) {
                if (isset($column['size'])) {
                    $table->column($column['name'], $column['type'], $column['size']);
                } else {
                    $table->column($column['name'], $column['type']);
                }
            }
            $table->create();

            $this->table->add($key, $table);
        }
    }
}
