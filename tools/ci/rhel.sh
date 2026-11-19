#!/bin/sh
# Runs the tests on a RHEL-family distribution's own PHP and OpenSSL (AlmaLinux: what cPanel and
# CloudLinux shared hosting runs on), inside its container, as root, on the repository in /app: a
# CI job's copy (tools/ci/in-image.sh), or docker run -v "$PWD:/app" … almalinux:<n> sh
# /app/tools/ci/rhel.sh. With SATTLEME_DIFFERENTIAL_COUNT (and SATTLEME_DIFFERENTIAL_SEED), it also generates
# libsecp256k1's cases for the differential tests; with SATTLEME_DIFFERENTIAL_CASES, it reads them from
# that file.
set -eu

COMPOSER_VERSION=2.11.2
COMPOSER_SHA256=dd158c0afd41d15fac89e1b4e47b5d42911fe316c3687cd5edac98319b25d028

. /etc/os-release
echo "$PRETTY_NAME"
case "$VERSION_ID" in
  8* | 9*)
    # PHP 8.2, the SDK's oldest, from the distribution's own module.
    dnf -y -q module reset php
    dnf -y -q module enable php:8.2
    ;;
esac
dnf -y -q install php-cli php-bcmath php-mbstring php-xml unzip git-core
php -v
php -r 'echo OPENSSL_VERSION_TEXT, PHP_EOL;'

curl -fsSL -o /tmp/composer.phar "https://getcomposer.org/download/${COMPOSER_VERSION}/composer.phar"
echo "${COMPOSER_SHA256}  /tmp/composer.phar" | sha256sum -c -
cd /app
git config --global --add safe.directory "$PWD"
export COMPOSER_ALLOW_SUPERUSER=1
php /tmp/composer.phar install --no-interaction --no-progress
php vendor/bin/phpunit
if [ -n "${SATTLEME_DIFFERENTIAL_COUNT:-}" ]; then
  dnf -y -q install python3.12
  sh tools/ci/cases.sh python3.12 "$SATTLEME_DIFFERENTIAL_COUNT" "${SATTLEME_DIFFERENTIAL_SEED:-1}" build/differential.json
  export SATTLEME_DIFFERENTIAL_CASES="$PWD/build/differential.json"
fi
if [ -n "${SATTLEME_DIFFERENTIAL_CASES:-}" ]; then
  # BIP-340 and ECDH on this distribution's OpenSSL against libsecp256k1's cases.
  php vendor/bin/phpunit --testsuite differential --group differential
fi
