#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
repository_root=$(cd -- "$script_dir/.." && pwd)
run_suffix=${GITHUB_RUN_ID:-$$}-${GITHUB_RUN_ATTEMPT:-local}
compose_project="davyro-calendar-phase0-contract-${run_suffix}"
test_image="davyro-calendar-test:phase0-${run_suffix}"
baikal_image="davyro-calendar-baikal-contract:0.12.1-${run_suffix}"
compose=(docker compose --project-name "$compose_project" -f "$repository_root/compose.contract-test.yaml")

cleanup() {
    "${compose[@]}" down --volumes >/dev/null 2>&1 || true
}
trap cleanup EXIT

export DAVYRO_CONTRACT_TEST_IMAGE="$test_image"
export DAVYRO_BAIKAL_CONTRACT_IMAGE="$baikal_image"

docker build --target test --tag "$test_image" "$repository_root"

docker run --rm "$test_image" sh -ec '
    test -x vendor/bin/phpunit
    test -x vendor/bin/php-cs-fixer
    test -x node_modules/.bin/ec
    composer validate --strict
    composer lint
    composer test
'

"${compose[@]}" build baikal
test "$(docker image inspect --format '{{ index .Config.Labels "org.opencontainers.image.version" }}' "$baikal_image")" = '0.12.1'
docker run --rm --entrypoint php "$baikal_image" -r '
    require "/var/www/baikal/Core/Distrib.php";
    exit(defined("BAIKAL_VERSION") && BAIKAL_VERSION === "0.12.1" ? 0 : 1);
'

"${compose[@]}" up --no-build --abort-on-container-exit --exit-code-from contract-tests
