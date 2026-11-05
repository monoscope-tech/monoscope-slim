<?php

namespace APIToolkit;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Routing\RouteContext;
use JsonPath\JsonObject;
use JsonPath\InvalidJsonException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Ramsey\Uuid\Uuid;
use Apitoolkit\Common\Shared;
use OpenTelemetry\API\Globals;

class APIToolkitMiddleware
{
  private ?string $serviceVersion;
  private array $tags;
  private array $redactHeaders = [];
  private array $redactRequestBody = [];
  private array $redactResponseBody = [];
  private $logger;
  private array $errors = [];
  private array $config = [];
  private bool $debug = false;
  private bool $captureRequestBody = false;
  private bool $captureResponseBody = false;

  public function __construct(array $config) {
    $this->redactHeaders = $config['redactHeaders'] ?? [];
    $this->redactRequestBody = $config['redactRequestBody'] ?? [];
    $this->redactResponseBody = $config['redactResponseBody'] ?? [];
    $this->captureRequestBody = $config['captureRequestBody'] ?? false;
    $this->captureResponseBody = $config['captureResponseBody'] ?? false;
    $this->debug = $config['debug'] ?? false;
    $this->serviceVersion = $config['serviceVersion'] ?? null;
    $this->tags = $config['tags'] ?? [];
    $this->config = $config;
    $this->logger = new Logger('APIToolkit');
    $this->logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

    if ($this->debug) {
      $this->logger->debug('APIToolkit: middleware initialized');
    }
  }

  public function __invoke(Request $request, RequestHandler $handler): Response
  {
    $tracerProvider = Globals::tracerProvider();
    $tracer = $tracerProvider->getTracer("apitoolkit-http-tracer");
    $span = $tracer->spanBuilder('apitoolkit-http-span')->startSpan();
    $newUuid = Uuid::uuid4();
    $msg_id = $newUuid->toString();
    $request = $request->withAttribute('apitoolkitData', [
      'msg_id' => $msg_id,
      "client" => $this,
    ]);
    $response = $handler->handle($request);
    $this->log($request, $response, $msg_id, $span);
    return $response;
  }

  public function addError($error)
  {
    $this->errors[] = $error;
  }

  public function log(Request $request, $response, $msg_id, $span)
  {
      $path = $request->getUri()->getPath();
      $query = $request->getUri()->getQuery();
      // No route when nothing matched (404) or routing hasn't run on this request yet.
      $route = $request->getAttribute(RouteContext::ROUTE);
      $pattern = $route ? $route->getPattern() : $path;
      $pathWithQuery = $path . ($query ? '?' . $query : '');
      $pathParams = $this->extractPathParams($pattern, $path);
      $resBodyContent =  "";
      if ($this->captureResponseBody) {
        $resBody = $response->getBody();
        $resBodyContent = $resBody ? (string) $resBody : "";
      }
      $reqBodyCotent = "";
      if ($this->captureRequestBody) {
        $reqBodyCotent = json_encode($request->getParsedBody()) ?? "";
      }
      Shared::setAttributes(
        $span,
        $request->getUri()->getAuthority(),
        $response->getStatusCode(),
        $request->getQueryParams(),
        $pathParams,
        $request->getHeaders(),
        $response->getHeaders(),
        $request->getMethod(),
        $pathWithQuery,
        $msg_id,
        $pattern,
        $reqBodyCotent,
        $resBodyContent,
        $this->errors,
        $this->config,
        'PhpSlim',
        null
      );
  }

  private function extractPathParams($pattern, $url)
  {
      $patternSegments = explode('/', trim($pattern, '/'));
      $urlSegments = explode('/', trim($url, '/'));
      $params = array();
      foreach ($patternSegments as $key => $segment) {
          if (strpos($segment, '{') === 0 && strpos($segment, '}') === strlen($segment) - 1) {
              $paramName = trim($segment, '{}');
              if (isset($urlSegments[$key])) {
                  $params[$paramName] = $urlSegments[$key];
              }
          }
      }
      return $params;
  }

  public static  function observeGuzzle($request, $options) {
    $apitoolkit = $request->getAttribute("apitoolkitData");
    $msgId = $apitoolkit['msg_id'];
    return Shared::observeGuzzle($options, $msgId);
  }

  public static function reportError($error, $request)
  {
    $apitoolkit = $request->getAttribute("apitoolkitData");
    $client = $apitoolkit['client'];
    Shared::reportError($error, $client);
  }
}


