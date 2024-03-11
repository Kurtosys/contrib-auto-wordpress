<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Wordpress;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;

final class HttpClientInstrumentation
{
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.wordpress_http');

        hook(
            'WpOrg\Requests\Requests',
            function: 'request',
            pre: static function ($client, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation): void {
                $urlParts = parse_url($params[0]);

                $method = $params[3];
                $uri = $params[0];
                $userAgent = $params[4]['useragent'];
                $host = $urlParts['host'];
                $port = $urlParts['port'] ?? 80;
                $path = $urlParts['path'];

                $builder = $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('%s', $method))
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::URL_FULL, $uri)
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $method)
                    ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $userAgent)
                    ->setAttribute(TraceAttributes::SERVER_ADDRESS, $host)
                    ->setAttribute(TraceAttributes::SERVER_PORT, $port)
                    ->setAttribute(TraceAttributes::URL_PATH, $path)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

                $propagator = Globals::propagator();
                $parent = Context::getCurrent();

                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $context = $span->storeInContext($parent);
                $headers = $params[1] ?? [];
                $propagator->inject($headers, ArrayAccessGetterSetter::getInstance(), $context);
                Context::storage()->attach($context);
            },
            post: static function ($class, $request, ?\WpOrg\Requests\Response $response, ?\WpOrg\Requests\Exception $exception): void {

                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());

                if ($exception) {
                    $span->recordException(new \Exception($exception->getMessage(), $exception->getCode(), $exception->getPrevious()), [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                if ($response) {
                    if ($response->status_code >= 400) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    } else {
                        $span->setStatus(StatusCode::STATUS_OK);
                    }
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->status_code);
                    $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->protocol_version);
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, mb_strlen($response->body));

                    $propagator = Globals::propagator();
                    $propagator->inject($response->headers, ArrayAccessGetterSetter::getInstance(), $scope->context());

                    foreach ($response->headers->getIterator() as $key => $value) {
                        $span->setAttribute(\sprintf('http.response.header.%s', strtolower($key)), $value);
                    }
                }
                $span->end();
            },
        );
    }
}
