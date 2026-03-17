#!/usr/bin/env bash
# bin/install-wp-tests.sh
#
# Installs the WordPress test suite for use with PHPUnit.
# Adapted from the WP CLI scaffold test command.
#
# Usage:
#   bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# Example:
#   bash bin/install-wp-tests.sh wordpress_tests root '' 127.0.0.1 latest

set -e

DB_NAME=${1:-wordpress_tests}
DB_USER=${2:-root}
DB_PASS=${3:-''}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}

WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}

download() {
    if [ "$(which curl)" ]; then
        curl -s "$1" > "$2"
    elif [ "$(which wget)" ]; then
        wget -nv -O "$2" "$1"
    fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
    WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
    WP_TESTS_TAG="trunk"
else
    if [ "$WP_VERSION" == 'latest' ]; then
        local_version=$(download https://api.wordpress.org/core/version-check/1.7/ - | grep -o '"version":"[^"]*"' | head -1 | cut -d'"' -f4)
        WP_VERSION="$local_version"
    fi
    WP_TESTS_TAG="tags/$WP_VERSION"
fi

set -ex

install_wp() {
    if [ -d "$WP_CORE_DIR" ]; then
        return
    fi
    mkdir -p "$WP_CORE_DIR"
    if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
        mkdir -p /tmp/wordpress-nightly
        download https://wordpress.org/nightly-builds/wordpress-latest.zip  /tmp/wordpress-nightly/wordpress-nightly.zip
        unzip -q /tmp/wordpress-nightly/wordpress-nightly.zip -d /tmp/wordpress-nightly/
        mv /tmp/wordpress-nightly/wordpress/* "$WP_CORE_DIR"
    else
        if [ "$WP_VERSION" == 'latest' ]; then
            local_version=$(download https://api.wordpress.org/core/version-check/1.7/ - | grep -o '"version":"[^"]*"' | head -1 | cut -d'"' -f4)
            WP_VERSION="$local_version"
        fi
        download https://wordpress.org/wordpress-${WP_VERSION}.tar.gz /tmp/wordpress.tar.gz
        tar --strip-components=1 -zxf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
    fi
}

install_test_suite() {
    if [ -d "$WP_TESTS_DIR" ]; then
        return
    fi
    mkdir -p "$WP_TESTS_DIR"

    # Try the requested tag/branch first; fall back to trunk on failure
    svn_checkout() {
        local path="$1" dest="$2"
        if ! svn co --quiet --no-auth-cache "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/${path}/" "${dest}" 2>/dev/null; then
            echo "SVN checkout of ${WP_TESTS_TAG}/${path} failed; falling back to trunk" >&2
            svn co --quiet --no-auth-cache "https://develop.svn.wordpress.org/trunk/${path}/" "${dest}"
        fi
    }

    svn_checkout "tests/phpunit/includes" "$WP_TESTS_DIR/includes"
    svn_checkout "tests/phpunit/data"     "$WP_TESTS_DIR/data"

    if [ ! -f "$WP_TESTS_DIR"/wp-tests-config.php ]; then
        local cfg_url="https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php"
        download "${cfg_url}" "$WP_TESTS_DIR"/wp-tests-config.php 2>/dev/null || \
            download "https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php" "$WP_TESTS_DIR"/wp-tests-config.php
        WP_CORE_DIR_ESC="${WP_CORE_DIR//\//\\/}"
        sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
        sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
        sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR"/wp-tests-config.php
        sed -i "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
        sed -i "s|dirname(__FILE__) . '/src/'|'${WP_CORE_DIR_ESC}/'|" "$WP_TESTS_DIR"/wp-tests-config.php
    fi
}

install_db() {
    if [ "${SKIP_DB_CREATE}" = "true" ]; then
        return
    fi
    mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true
}

install_wp
install_test_suite
install_db
