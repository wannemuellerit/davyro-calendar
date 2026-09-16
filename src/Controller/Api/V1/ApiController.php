<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\Exception\ConnectionProblem;
use AgenDAV\Exception\ElementModified;
use AgenDAV\Exception\NotFound;
use AgenDAV\Exception\PermissionDenied;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

abstract class ApiController
{
    /** @param array<string, mixed> $payload */
    protected function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    protected function error(ResponseInterface $response, string $code, string $message, int $status): ResponseInterface
    {
        return $this->json($response, ['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if (!str_contains($contentType, 'application/json')) {
            throw new \InvalidArgumentException('Content-Type must be application/json');
        }
        $value = json_decode((string) $request->getBody(), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new \InvalidArgumentException('JSON object expected');
        }

        return $value;
    }

    protected function guarded(ResponseInterface $response, callable $operation): ResponseInterface
    {
        try {
            return $operation();
        } catch (ApiNotFound|NotFound|PermissionDenied) {
            return $this->error($response, 'not_found', 'Resource not found', 404);
        } catch (ElementModified) {
            return $this->error($response, 'etag_mismatch', 'The calendar resource has changed', 412);
        } catch (ApiPreconditionFailed $exception) {
            return $this->error($response, 'etag_mismatch', $exception->getMessage(), 412);
        } catch (ApiPreconditionRequired $exception) {
            return $this->error($response, 'precondition_required', $exception->getMessage(), 428);
        } catch (ApiConflict|\DomainException $exception) {
            return $this->error($response, 'conflict', $exception->getMessage(), 409);
        } catch (ConnectionProblem) {
            return $this->error($response, 'caldav_unavailable', 'Calendar service is unavailable', 503);
        } catch (\AgenDAV\Exception) {
            return $this->error($response, 'caldav_error', 'Calendar operation failed', 502);
        } catch (\JsonException|\InvalidArgumentException|ApiValidation $exception) {
            return $this->error($response, 'validation_failed', $exception->getMessage(), 422);
        } catch (\Throwable) {
            return $this->error($response, 'internal_error', 'Calendar operation failed', 500);
        }
    }
}

final class ApiNotFound extends \RuntimeException
{
}

final class ApiConflict extends \RuntimeException
{
}

final class ApiValidation extends \RuntimeException
{
}

final class ApiPreconditionFailed extends \RuntimeException
{
}

final class ApiPreconditionRequired extends \RuntimeException
{
}
