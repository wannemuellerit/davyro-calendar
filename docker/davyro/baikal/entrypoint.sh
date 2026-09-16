#!/bin/sh
set -eu

php /opt/davyro/bootstrap.php

exec "$@"
