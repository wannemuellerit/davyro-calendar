#!/usr/bin/env bash
set -u
php-fpm --nodaemonize &
php_pid=$!
httpd -D FOREGROUND &
httpd_pid=$!
cleanup() {
    kill -TERM "$php_pid" "$httpd_pid" 2>/dev/null || true
    wait "$php_pid" "$httpd_pid" 2>/dev/null || true
}
trap cleanup EXIT
trap 'exit 0' TERM INT
# Stop the container if either service exits; never leave a broken half alive.
if wait -n "$php_pid" "$httpd_pid"; then
    exit 1
else
    exit "$?"
fi
