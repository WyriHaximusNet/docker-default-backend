<?php

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Factory;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Http\Server as HttpServer;
use ReactInspector\Collector\Merger\CollectorMergerCollector;
use ReactInspector\EventLoop\LoopCollector;
use ReactInspector\EventLoop\LoopDecorator;
use ReactInspector\Http\Middleware\Printer\PrinterMiddleware;
use ReactInspector\HttpMiddleware\MiddlewareCollector;
use ReactInspector\MemoryUsage\MemoryUsageCollector;
use ReactInspector\Metrics;
use ReactInspector\Printer\Prometheus\PrometheusPrinter;
use ReactInspector\Stream\IOCollector;
use Symfony\Component\Yaml\Yaml;
use WyriHaximus\React\Http\Middleware\Header;
use WyriHaximus\React\Http\Middleware\WithHeadersMiddleware;
use const WyriHaximus\FakePHPVersion\CURRENT;

require 'vendor/autoload.php';

$indexHtml = \Safe\file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.html');
Loop::set(new LoopDecorator(Loop::get()));

$metrics = [];
$middleware = [];
$metricsMiddleware = [];

$extraHeaders = new WithHeadersMiddleware(
    new Header('Server', 'wyrihaximusnet/redirect (https://hub.docker.com/r/wyrihaximusnet/default-backend)'),
    new Header('X-Powered-By', 'PHP/' . CURRENT),
);

$middleware[] = $extraHeaders;
$metricsMiddleware[] = $extraHeaders;
$middlewareCollectornotFound = new MiddlewareCollector('notFound');
$middlewareCollectorMetrics = new MiddlewareCollector('metrics');
$middleware[] = $middlewareCollectornotFound;
$metricsMiddleware[] = $middlewareCollectorMetrics;

$metricsMiddleware[] = new PrinterMiddleware(new PrometheusPrinter(), new Metrics(
    Loop::get(),
    3,
    new LoopCollector(Loop::get()),
    new MemoryUsageCollector(),
    new IOCollector(),
    new CollectorMergerCollector(
        $middlewareCollectornotFound,
        $middlewareCollectorMetrics
    )
));

$middleware[] = function (ServerRequestInterface $request) use ($indexHtml): ResponseInterface {
    return new Response(
        404,
        [
            'Content-Type' => 'text/html',
        ],
        $indexHtml
    );
};

$server = new HttpServer(...$middleware);
$server->on('error', static function (Throwable $throwable): void {
    echo $throwable, PHP_EOL;
});

$socket = new React\Socket\Server('0.0.0.0:6969', null, ['backlog' => 511]);
$socket->on('error', static function (Throwable $throwable): void {
    echo $throwable, PHP_EOL;
});
$server->listen($socket);

$metricsServer = new HttpServer(...$metricsMiddleware);
$metricsServer->on('error', static function (Throwable $throwable): void {
    echo $throwable, PHP_EOL;
});

$metricsSocket = new React\Socket\Server('0.0.0.0:9696', null, ['backlog' => 511]);
$metricsSocket->on('error', static function (Throwable $throwable): void {
    echo $throwable, PHP_EOL;
});
$metricsServer->listen($metricsSocket);

$signalHandler = function () use (&$signalHandler, $socket, $metricsSocket) {
    echo 'Caught signal', PHP_EOL;
    Loop::removeSignal(SIGINT, $signalHandler);
    Loop::removeSignal(SIGTERM, $signalHandler);
    $socket->close();
    $metricsSocket->close();
    echo 'Closed and stopped everything', PHP_EOL;
    Loop::stop();
};

Loop::addSignal(SIGINT, $signalHandler);
Loop::addSignal(SIGTERM, $signalHandler);
