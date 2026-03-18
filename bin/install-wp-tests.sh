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
        if [ "$2" = "-" ]; then
            curl -s "$1"
        else
            curl -s "$1" > "$2"
        fi
    elif [ "$(which wget)" ]; then
        if [ "$2" = "-" ]; then
            wget -nv -O - "$1"
        else
            wget -nv -O "$2" "$1"
        fi
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
    svn_co_with_fallback() {
        local path="$1"
        local dest="$2"
        svn co --quiet --no-auth-cache "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/${path}/" "${dest}" || \
            svn co --quiet --no-auth-cache "https://develop.svn.wordpress.org/trunk/${path}/" "${dest}"
    }

    svn_co_with_fallback "tests/phpunit/includes" "$WP_TESTS_DIR/includes"
    svn_co_with_fallback "tests/phpunit/data"     "$WP_TESTS_DIR/data"

    if [ ! -f "$WP_TESTS_DIR"/wp-tests-config.php ]; then
        download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" \
            "$WP_TESTS_DIR"/wp-tests-config.php || \
            download "https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php" \
                "$WP_TESTS_DIR"/wp-tests-config.php
        WP_CORE_DIR_ESC="${WP_CORE_DIR//\//\\/}"
        sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
        sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
        sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR"/wp-tests-config.php
        sed -i "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
        sed -i "s|dirname(__FILE__) . '/src/'|'${WP_CORE_DIR_ESC}/'|" "$WP_TESTS_DIR"/wp-tests-config.php
    fi

    # PHPUnit 10 removed PHPUnit\Util\Test::parseTestMethodAnnotations().
    # Patch abstract-testcase.php to guard the call so the WP test suite works
    # with both PHPUnit 9 and PHPUnit 10.
    if [ -f "$WP_TESTS_DIR/includes/abstract-testcase.php" ]; then
        python3 - "$WP_TESTS_DIR/includes/abstract-testcase.php" <<'PYEOF'
import re, sys
fname = sys.argv[1]
with open(fname) as fh:
    code = fh.read()
if 'parseTestMethodAnnotations' in code:
    code = re.sub(
        r'(\$annotations\s*=\s*)\\PHPUnit\\Util\\Test::parseTestMethodAnnotations\(\s*static::class,\s*\$this->getName\(\s*false\s*\)\s*\)',
        r'\1( method_exists( \\PHPUnit\\Util\\Test::class, \'parseTestMethodAnnotations\' ) '
        r'? \\PHPUnit\\Util\\Test::parseTestMethodAnnotations( static::class, $this->getName( false ) ) '
        r': array( \'class\' => array(), \'method\' => array() ) )',
        code)
    with open(fname, 'w') as fh:
        fh.write(code)
    print('Patched abstract-testcase.php for PHPUnit 10 compatibility.')
PYEOF
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
