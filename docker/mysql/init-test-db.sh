#!/bin/sh
set -e

mysql -u root -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS prestamos_test;
    GRANT ALL PRIVILEGES ON prestamos_test.* TO '${MYSQL_USER}'@'%';
    FLUSH PRIVILEGES;
EOSQL
