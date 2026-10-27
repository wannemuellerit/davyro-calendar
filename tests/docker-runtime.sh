#!/usr/bin/env bash
# Exercise the actual Apache/FPM process pair without application DBs or ports.
set -euo pipefail
image=${1:?Usage: docker-runtime.sh IMAGE DOCUMENT_ROOT}
web_root=${2:?Document root required}
name="davyro-runtime-smoke-$$"
fixture=$(mktemp -d)
cleanup() { docker rm -f "$name" >/dev/null 2>&1 || true; rm -rf "$fixture"; }
trap cleanup EXIT
chmod 755 "$fixture"
cat > "$fixture/index.php" <<'PHP'
<?php
header('Content-Type: application/json');
echo json_encode(['runtime' => 'ok', 'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'environment' => getenv('DAVYRO_RUNTIME_TEST_SECRET')]);
PHP
cat > "$fixture/.htaccess" <<'HTACCESS'
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
HTACCESS
printf 'private-fixture\n' > "$fixture/.env"
chmod 644 "$fixture/"* "$fixture/.htaccess" "$fixture/.env"
docker run -d --name "$name" --network none --entrypoint apache2-foreground \
    -e DAVYRO_RUNTIME_TEST_SECRET=fixture-only \
    -v "$fixture:$web_root:ro" "$image" >/dev/null
for attempt in $(seq 1 30); do
    if docker exec "$name" php -r 'exit(@file_get_contents("http://127.0.0.1/") === false ? 1 : 0);' 2>/dev/null; then break; fi
    sleep 1
done
[[ "$(docker exec "$name" id -u www-data)" == 33 ]]
[[ "$(docker exec "$name" id -g www-data)" == 33 ]]
docker exec -i "$name" php <<'PHP'
<?php
function request(string $path): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'header' => "Authorization: Bearer runtime-test\r\n"]]);
    $body = file_get_contents('http://127.0.0.1'.$path, false, $ctx);
    return [http_get_last_response_headers()[0], $body];
}
[$status, $body] = request('/rewritten/route?ticket=do-not-log-this-token');
$data = json_decode($body, true);
if (!str_contains($status, '200') || $data !== ['runtime' => 'ok', 'authorization' => 'Bearer runtime-test', 'environment' => 'fixture-only']) {
    throw new RuntimeException('PHP, rewrite, Authorization or environment propagation failed: '.json_encode([$status,$body]));
}
[$status, $body] = request('/.env');
if (!str_contains($status, '403') || str_contains($body, 'private-fixture')) {
    throw new RuntimeException('Hidden file protection failed');
}
echo "PHP execution, rewrites, Authorization, environment and hidden-file protection: OK\n";
PHP
logs=$(docker logs "$name" 2>&1)
if [[ "$logs" == *do-not-log-this-token* ]]; then echo 'Query token leaked into access log' >&2; exit 1; fi
docker stop --time 10 "$name" >/dev/null
status=$(docker inspect "$name" --format '{{.State.ExitCode}}')
[[ "$status" == 0 ]] || { echo "Ungraceful stop: $status" >&2; exit 1; }
echo 'Query redaction and graceful shutdown: OK'

# Losing FPM must also stop Apache, rather than keeping a broken server alive.
docker start "$name" >/dev/null
for attempt in $(seq 1 30); do
    if docker exec "$name" php -r 'exit(@file_get_contents("http://127.0.0.1/") === false ? 1 : 0);' 2>/dev/null; then break; fi
    sleep 1
done
docker exec "$name" sh -c 'killall php-fpm'
for attempt in $(seq 1 20); do
    [[ "$(docker inspect "$name" --format '{{.State.Running}}')" == false ]] && break
    sleep 1
done
[[ "$(docker inspect "$name" --format '{{.State.Running}}')" == false ]]
[[ "$(docker inspect "$name" --format '{{.State.ExitCode}}')" != 0 ]]
echo 'Preserved volume ownership and service failure propagation: OK'
