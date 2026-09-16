<?php

namespace AgenDAV;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Slim\Psr7\Response;

/**
 * Picks errors/<code>.html (or errors/<digit>x.html / errors/<digit>xx.html)
 * to render unhandled exceptions, falling back to errors/default.html. In
 * debug mode renders the exception details inline so stack traces show up.
 */
class ErrorHandler
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function __invoke(
        ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): ResponseInterface {
        $code = $exception instanceof HttpException ? $exception->getCode() : 500;

        $path = $request->getUri()->getPath();
        $basePath = rtrim((string) ($this->container->has('app.base_path')
            ? $this->container->get('app.base_path')
            : ''), '/');
        if ($basePath !== '' && str_starts_with($path, $basePath.'/')) {
            $path = substr($path, strlen($basePath));
        }
        if (str_starts_with($path, '/api/v1/') || str_starts_with($path, '/internal/davyro/')) {
            $response = new Response();
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'code' => $code === 401 ? 'unauthenticated' : ($code === 404 ? 'not_found' : 'request_failed'),
                    'message' => $code >= 500 ? 'Calendar operation failed' : $exception->getMessage(),
                ],
            ], JSON_UNESCAPED_SLASHES));

            return $response
                ->withStatus($code)
                ->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        if ($displayErrorDetails) {
            // Class + message + file:line is enough to diagnose in the
            // browser. Stack traces are NOT echoed: if AGENDAV_ENVIRONMENT
            // ever ends up 'dev' on a public host, the trace would leak
            // file paths, class internals and any argument values captured
            // by closures (potentially secrets). Operators wanting the full
            // trace should consult var/log/<today>.log — addErrorMiddleware
            // is configured with logErrorDetails=true, so the trace is there.
            $body = sprintf(
                '<h1>%d %s</h1><pre>%s</pre>',
                $code,
                htmlspecialchars(get_class($exception) . ': ' . $exception->getMessage()),
                htmlspecialchars(sprintf('%s:%d', $exception->getFile(), $exception->getLine()))
            );
            $response = new Response();
            $response->getBody()->write($body);
            return $response->withStatus($code)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        $templates = [
            sprintf('errors/%d.html', $code),
            sprintf('errors/%dx.html', intdiv($code, 10)),
            sprintf('errors/%dxx.html', intdiv($code, 100)),
            'errors/default.html',
        ];

        $body = $this->container->get('twig')->resolveTemplate($templates)->render([
            'code' => $code,
            'message' => $exception->getMessage(),
        ]);

        $response = new Response();
        $response->getBody()->write($body);
        return $response->withStatus($code);
    }
}
