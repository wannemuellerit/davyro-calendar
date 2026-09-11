#!/bin/bash
set -Eeuo pipefail

for variable in BAIKAL_DB_NAME BAIKAL_DB_USER BAIKAL_DB_PASSWORD; do
    if [ -z "${!variable:-}" ]; then
        echo "Missing required variable: ${variable}" >&2
        return 1
    fi
done

if [[ ! "$BAIKAL_DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || [[ ! "$BAIKAL_DB_USER" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "Baikal database and user names may only contain letters, digits and underscores" >&2
    return 1
fi

escaped_password=${BAIKAL_DB_PASSWORD//\\/\\\\}
escaped_password=${escaped_password//\'/\'\'}

docker_process_sql --database=mysql <<-EOSQL
CREATE DATABASE IF NOT EXISTS \`$BAIKAL_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$BAIKAL_DB_USER'@'%' IDENTIFIED BY '$escaped_password';
ALTER USER '$BAIKAL_DB_USER'@'%' IDENTIFIED BY '$escaped_password';
GRANT ALL PRIVILEGES ON \`$BAIKAL_DB_NAME\`.* TO '$BAIKAL_DB_USER'@'%';
FLUSH PRIVILEGES;
EOSQL
