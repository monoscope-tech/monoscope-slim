<?php

namespace Tests;

use APIToolkit\APIToolkitMiddleware;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;

class APIToolkitMiddlewareTest extends TestCase
{
  private InMemoryExporter $exporter;
  private $scope;

  protected function setUp(): void
  {
    $this->exporter = new InMemoryExporter();
    $this->scope = Configurator::create()->withTracerProvider(new TracerProvider(new SimpleSpanProcessor($this->exporter)))->activate();
  }

  protected function tearDown(): void
  {
    $this->scope->detach();
  }

  private function app(): App
  {
    $app = AppFactory::create();
    $app->get('/users/{id}', fn($req, $res) => $res);
    return $app;
  }

  private function spanAttributes(): array
  {
    return $this->exporter->getSpans()[0]->getAttributes()->toArray();
  }

  public function setups(): array
  {
    return [
      'routing runs before the middleware' => [function (App $app) {
        $app->add(new APIToolkitMiddleware([]));
        $app->addRoutingMiddleware();
      }, '/users/42', 200, '/users/{id}', '{"id":"42"}'],
      'routing runs after the middleware (README setup)' => [function (App $app) {
        $app->add(new APIToolkitMiddleware([]));
      }, '/users/42', 200, '/users/42', '[]'],
      'unmatched path answered by an inner error middleware' => [function (App $app) {
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        $app->add(new APIToolkitMiddleware([]));
      }, '/nope', 404, '/nope', '[]'],
    ];
  }

  /** @dataProvider setups */
  public function test_records_route_pattern_or_raw_path(callable $setup, string $path, int $status, string $route, string $pathParams): void
  {
    $app = $this->app();
    $setup($app);
    $this->assertSame($status, $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path))->getStatusCode());
    $attrs = $this->spanAttributes();
    $this->assertSame($route, $attrs['http.route']);
    $this->assertSame($path, $attrs['http.target']);
    $this->assertSame($pathParams, $attrs['http.request.path_params']);
  }

  public function test_logs_raw_path_when_routing_found_no_route(): void
  {
    $app = $this->app();
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/nope')
      ->withAttribute(RouteContext::ROUTE_PARSER, $app->getRouteCollector()->getRouteParser())
      ->withAttribute(RouteContext::ROUTING_RESULTS, $app->getRouteResolver()->computeRoutingResults('/nope', 'GET'));
    $span = \OpenTelemetry\API\Globals::tracerProvider()->getTracer('test')->spanBuilder('s')->startSpan();
    (new APIToolkitMiddleware([]))->log($request, (new ResponseFactory())->createResponse(404), 'id', $span);
    $this->assertSame('/nope', $this->spanAttributes()['http.route']);
  }
}
