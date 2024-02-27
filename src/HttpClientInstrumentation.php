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
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class HttpClientInstrumentation
{
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.wordpress_http');

        hook(
            class: \WpOrg\Requests\Transport::class,
            function: 'request',
            pre: static function (
                \WpOrg\Requests\Transport\Curl $client,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                $urlParts = parse_url($params[0]);

                $method = $params[3]['type'];
                $uri = $params[0];
                $protocolVersion = $params[3]['protocol_version'];
                $userAgent = $params[3]['useragent'];
                $host = $urlParts['host'];
                $port = $urlParts['port'];
                $path = $urlParts['path'];

                $spanBuilder = self::builder($httpInstrument, $method, $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::URL_FULL, $uri)
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $method)
                    ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $protocolVersion)
                    ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $userAgent)
                    ->setAttribute(TraceAttributes::SERVER_ADDRESS, $host)
                    ->setAttribute(TraceAttributes::SERVER_PORT, $port)
                    ->setAttribute(TraceAttributes::URL_PATH, $path);

                foreach ((array) $params[1] as $header => $value) {
                    $spanBuilder->setAttribute(
                        sprintf('http.request.header.%s', strtolower($header)), 
                        $value
                    );
                }
                $span = $spanBuilder->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
                return $request;
            },
            post: static function (
                \WpOrg\Requests\Transport\Curl $client,
                array $params,
                ?$response,
                ?\Throwable $exception
            ): void {
                self::end($exception);
            },
        );
    }
}