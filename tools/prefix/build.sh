#!/bin/sh
# Prefixes the SDK as a plugin embeds it, then runs the SDK's whole test suite against the
# prefixed copy (PORTING.md, "Packages that depend on an SDK"):
#   strauss: a WordPress plugin (WooCommerce), with Strauss;
#   scoper:  a PrestaShop module, with PHP-Scoper.
# The package is the one Packagist distributes: git archive, .gitattributes applied.
#
# Usage: tools/prefix/build.sh strauss|scoper <tool.phar>   (from the repository's root, after composer install)
set -eu

TOOL="$1"
PHAR="$(cd "$(dirname "$2")" && pwd)/$(basename "$2")"
ROOT="$(pwd)"
PREFIX='AcmeShop\Vendor'
# A new directory each run: nothing is ever deleted.
mkdir -p "$ROOT/build"
WORK="$(mktemp -d "$ROOT/build/prefix-$TOOL.XXXXXX")"
cd "$WORK"
echo "Working in $WORK"

# 1. The distributed package: nothing of tests/, spec/, tools/ nor the CI.
mkdir package
git -C "$ROOT" archive --format=tar HEAD | tar -x -C package
for excluded in tests spec tools .github phpunit.xml.dist phpstan.neon.dist .php-cs-fixer.dist.php composer.lock; do
  if [ -e "package/$excluded" ]; then
    echo "The distributed package holds $excluded: .gitattributes must leave it out" >&2
    exit 1
  fi
done

# 2. The plugin, its dependencies installed without the development ones.
cat > composer.json <<JSON
{
  "name": "acmeshop/plugin",
  "type": "project",
  "repositories": [{ "type": "path", "url": "package", "options": { "symlink": false, "versions": { "sattleme/checkout": "1.0.0" } } }],
  "require": { "sattleme/checkout": "1.0.0" },
  "config": { "platform": { "php": "8.2.0" } },
  "extra": {
    "strauss": {
      "target_directory": "vendor-prefixed",
      "namespace_prefix": "AcmeShop\\\\Vendor\\\\",
      "classmap_prefix": "AcmeShop_Vendor_",
      "constant_prefix": "ACMESHOP_VENDOR_",
      "packages": ["sattleme/checkout"],
      "delete_vendor_packages": true
    }
  }
}
JSON
composer install --no-dev --no-interaction --no-progress --quiet

# 3. Prefixed: its autoloader in $AUTOLOAD.
case "$TOOL" in
  strauss)
    php "$PHAR" --info
    AUTOLOAD="$WORK/vendor-prefixed/autoload.php"
    PREFIXED="$WORK/vendor-prefixed"
    ;;
  scoper)
    cat > scoper.inc.php <<'PHP'
<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
    'prefix' => 'AcmeShop\Vendor',
    'finders' => [Finder::create()->files()->in('vendor/sattleme/checkout')->name(['*.php', 'composer.json', 'LICENSE'])],
];
PHP
    php "$PHAR" add-prefix --config=scoper.inc.php --output-dir=scoped --force --no-interaction
    # The scoped package (its paths relative to it), with an autoloader of its own, as PrestaShop modules make it.
    cat > scoped/composer.json <<'JSON'
{ "autoload": { "psr-4": { "AcmeShop\\Vendor\\Sattleme\\Checkout\\": "src/" } } }
JSON
    composer dump-autoload --working-dir=scoped --classmap-authoritative --no-interaction --quiet
    AUTOLOAD="$WORK/scoped/vendor/autoload.php"
    PREFIXED="$WORK/scoped/src"
    ;;
  *)
    echo "strauss or scoper" >&2
    exit 2
    ;;
esac

# Nothing of the SDK left under its own namespace.
if grep -rl --include='*.php' '^namespace Sattleme' "$PREFIXED"; then
  echo "Files left unprefixed" >&2
  exit 1
fi

# 4. The SDK's tests, their namespace prefixed as the code's, against the prefixed copy alone.
mkdir -p run/.github/workflows run/tools/differential
cp -r "$ROOT/tests" "$ROOT/spec" "$ROOT/README.md" "$ROOT/composer.json" run/
# What the README's test compares it with.
cp "$ROOT/.github/workflows/ci.yml" run/.github/workflows/
cp "$ROOT/tools/differential/requirements.txt" run/tools/differential/
find run/tests -name '*.php' -exec sed -i \
  -e 's/Sattleme\\Checkout\\/AcmeShop\\Vendor\\Sattleme\\Checkout\\/g' \
  -e 's/Sattleme\\\\Checkout\\\\/AcmeShop\\\\Vendor\\\\Sattleme\\\\Checkout\\\\/g' {} +
cat > run/bootstrap.php <<PHP
<?php

declare(strict_types=1);

// PHPUnit from the repository's development dependencies, never its unprefixed SDK.
\$loader = require '$ROOT/vendor/autoload.php';
\$loader->setPsr4('Sattleme\\\\Checkout\\\\', []);
\$loader->setPsr4('Sattleme\\\\Checkout\\\\Tests\\\\', []);
\$loader->addPsr4('AcmeShop\\\\Vendor\\\\Sattleme\\\\Checkout\\\\Tests\\\\', __DIR__ . '/tests');
require '$AUTOLOAD';
register_shutdown_function(static function (): void {
    foreach (get_declared_classes() as \$class) {
        if (str_starts_with(\$class, 'Sattleme\\\\')) {
            fwrite(STDERR, "The unprefixed SDK was loaded: {\$class}\\n");
            exit(1);
        }
    }
});
PHP
sed -e 's#bootstrap="vendor/autoload.php"#bootstrap="bootstrap.php"#' \
    -e 's#vendor/phpunit/phpunit/phpunit.xsd#'"$ROOT"'/vendor/phpunit/phpunit/phpunit.xsd#' \
    -e 's#<directory>src</directory>#<directory>'"$PREFIXED"'</directory>#' \
    "$ROOT/phpunit.xml.dist" > run/phpunit.xml
php "$ROOT/vendor/bin/phpunit" -c run/phpunit.xml

# 5. For a WordPress plugin: its ZIP under 10 MB (the directory's limit).
if [ "$TOOL" = strauss ]; then
  mkdir -p zip/acmeshop
  cp -r vendor-prefixed zip/acmeshop/
  (cd zip && php -r '$z = new ZipArchive(); $z->open("plugin.zip", ZipArchive::CREATE); $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("acmeshop", FilesystemIterator::SKIP_DOTS)); foreach ($it as $f) { $z->addFile($f->getPathname()); } $z->close();')
  SIZE=$(wc -c < zip/plugin.zip)
  echo "The plugin's ZIP with the prefixed SDK: $SIZE bytes"
  if [ "$SIZE" -ge 10485760 ]; then
    echo "Over 10 MB" >&2
    exit 1
  fi
fi
echo "Prefixed with $TOOL: the whole test suite passed"
