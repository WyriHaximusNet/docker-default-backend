<?php

use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use React\Cache\ArrayCache;
use React\EventLoop\Factory;
use React\Promise\PromiseInterface;
use ReactInspector\Collector\Merger\CollectorMergerCollector;
use ReactInspector\EventLoop\LoopCollector;
use ReactInspector\EventLoop\LoopDecorator;
use ReactInspector\Http\Middleware\Printer\PrinterMiddleware;
use ReactInspector\HttpMiddleware\MiddlewareCollector;
use ReactInspector\MemoryUsage\MemoryUsageCollector;
use ReactInspector\Metrics;
use ReactInspector\Printer\Prometheus\PrometheusPrinter;
use ReactInspector\Stream\IOCollector;
use ReactInspector\Tag;
use ReactInspector\Tags;
use Symfony\Component\Yaml\Yaml;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Response;
use React\Http\Server as HttpServer;
use WyriHaximus\React\Http\Middleware\RewriteMiddleware;
use WyriHaximus\React\Http\Middleware\WebrootPreloadMiddleware;
use WyriHaximus\React\Http\Middleware\WithHeadersMiddleware;
use function React\Promise\resolve;
use const WyriHaximus\FakePHPVersion\CURRENT;

require 'vendor/autoload.php';

$loop = new LoopDecorator(Factory::create());

$metrics = [];
$middleware = [];
$metricsMiddleware = [];

$extraHeaders = new WithHeadersMiddleware([
    'Server' => 'wyrihaximusnet/redirect (https://hub.docker.com/r/wyrihaximusnet/default-backend)',
    'X-Powered-By' => 'PHP/' . CURRENT,
]);

$middleware[] = $extraHeaders;
$metricsMiddleware[] = $extraHeaders;
$middlewareCollectornotFound = new MiddlewareCollector('notFound');
$middlewareCollectorMetrics = new MiddlewareCollector('metrics');
$middleware[] = $middlewareCollectornotFound;
$metricsMiddleware[] = $middlewareCollectorMetrics;

$metricsMiddleware[] = new PrinterMiddleware(new PrometheusPrinter(), new Metrics(
    $loop,
    3,
    new LoopCollector($loop),
    new MemoryUsageCollector(),
    new IOCollector(),
    new CollectorMergerCollector(
        $middlewareCollectornotFound,
        $middlewareCollectorMetrics
    )
));

$middleware[] = function (ServerRequestInterface $request, callable $next): PromiseInterface {
    if ($request->getUri()->getPath() === '/') {
        return resolve($next($request))->then(function (ResponseInterface $response): ResponseInterface {
            return $response->withStatus(404);
        });
    }

    return resolve($next($request));
};
$middleware[] = new RewriteMiddleware([
    '/' => '/index.html',
]);
$middleware[] = new WebrootPreloadMiddleware(
    __DIR__ . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR,
    new NullLogger(),
    new ArrayCache()
);
$middleware[] = function (ServerRequestInterface $request): ResponseInterface {
    return new Response(
        301,
        [
            'Location' => '/',
        ]
    );
};

$server = new HttpServer($middleware);
$server->on('error', static function (Throwable $throwable): void {
    echo $throwable, PHP_EOL;
});

$socket = new React\Socket\Server('0.0.0.0:6969', $loop, ['backlog' => 511]);
$socket->on('error', static function (Throwable $throwable): void {
    echo $throwable, PHP_EOL;
});
$server->listen($socket);

$metricsServer = new HttpServer($metricsMiddleware);
$metricsServer->on('error', static function (Throwable $throwable): void {
    echo $throwable, PHP_EOL;
});

$metricsSocket = new React\Socket\Server('0.0.0.0:9696', $loop, ['backlog' => 511]);
$metricsSocket->on('error', static function (Throwable $throwable): void {
    echo $throwable, PHP_EOL;
});
$metricsServer->listen($metricsSocket);

$signalHandler = function () use (&$signalHandler, $socket, $metricsSocket, $loop) {
    $loop->removeSignal(SIGINT, $signalHandler);
    $loop->removeSignal(SIGTERM, $signalHandler);
    $socket->close();
    $metricsSocket->close();
};

$loop->addSignal(SIGINT, $signalHandler);
$loop->addSignal(SIGTERM, $signalHandler);

echo 'Loop::run()', PHP_EOL;
$loop->run();
echo 'Loop::stop()', PHP_EOL;
