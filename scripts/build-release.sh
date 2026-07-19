#!/usr/bin/env bash

set -euo pipefail

tag=${1:-}
output_dir=${2:-dist}

if [[ ! $tag =~ ^v?[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
  printf 'Usage: %s <version-or-tag> [output-directory]\n' "$0" >&2
  exit 64
fi

version=${tag#v}
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
output_dir=$(mkdir -p "$output_dir" && cd "$output_dir" && pwd)
package="cogwork-engine-${version}"
archive="${output_dir}/${package}.zip"
checksum="${archive}.sha256"
staging=$(mktemp -d)
trap 'rm -rf "$staging"' EXIT

mkdir -p "$staging/$package/config" "$staging/$package/storage"

cp "$root/.htaccess" "$root/index.php" "$root/CHANGELOG.md" "$root/LICENSE" \
  "$root/README.md" "$root/SECURITY.md" "$root/VERSION" "$staging/$package/"
cp -R "$root/app" "$root/assets" "$root/lang" "$staging/$package/"
if [[ -d "$root/vendor" ]]; then
  cp -R "$root/vendor" "$staging/$package/"
fi
cp "$root/config/.htaccess" "$staging/$package/config/.htaccess"
cp "$root/storage/.htaccess" "$root/storage/.gitignore" \
  "$staging/$package/storage/"

(
  cd "$staging/$package"
  find app assets lang -type f -print0 | sort -z | xargs -0 sha256sum > .release-manifest.sha256
  sha256sum .htaccess index.php VERSION >> .release-manifest.sha256
)

rm -f "$archive" "$checksum"
(
  cd "$staging"
  zip -X -q -r "$archive" "$package"
)
(
  cd "$output_dir"
  sha256sum "$(basename "$archive")" > "$(basename "$checksum")"
)

printf 'Created %s\nCreated %s\n' "$archive" "$checksum"
