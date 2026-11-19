#!/bin/sh
# Prepares a CI job in PHP's official Docker image (Debian), run as root: git and unzip (for
# Composer), the extensions given ($@, among bcmath, gmp and zip; openssl and mbstring are built
# in), Composer at a pinned version and checksum, and the tests' ini settings.
# Usage: sh tools/ci/php-image.sh bcmath [gmp] [zip]
set -eu

COMPOSER_VERSION=2.11.2
COMPOSER_SHA256=dd158c0afd41d15fac89e1b4e47b5d42911fe316c3687cd5edac98319b25d028

packages="git unzip"
for extension in "$@"; do
  case "$extension" in
    bcmath) ;;
    gmp) packages="$packages libgmp-dev" ;;
    zip) packages="$packages libzip-dev" ;;
    *) echo "Unknown extension: $extension" >&2; exit 1 ;;
  esac
done
apt-get update -qq
# shellcheck disable=SC2086 # one package a word
apt-get install -y -qq --no-install-recommends $packages >/dev/null
docker-php-ext-install -j"$(nproc)" "$@" >/dev/null
printf 'zend.assertions=1\nerror_reporting=-1\n' > "$PHP_INI_DIR/conf.d/zz-ci.ini"

curl -fsSL -o /usr/local/bin/composer "https://getcomposer.org/download/${COMPOSER_VERSION}/composer.phar"
echo "${COMPOSER_SHA256}  /usr/local/bin/composer" | sha256sum -c -
chmod +x /usr/local/bin/composer

# A checkout mounted from the host (docker run -v) is not root's: git (the prefixed copy's
# `git archive`) asks for this.
git config --global --add safe.directory "$PWD"

php -v
php -r 'echo OPENSSL_VERSION_TEXT, PHP_EOL;'
php -r 'echo "gmp: ", extension_loaded("gmp") ? "yes" : "no", PHP_EOL;'
