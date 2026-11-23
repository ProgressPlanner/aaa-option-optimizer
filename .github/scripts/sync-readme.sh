#!/usr/bin/env bash
set -euo pipefail

# Only update an existing release; never create or switch the stable tag.
read_stable_tag() {
  sed -nE 's/^[Ss][Tt][Aa][Bb][Ll][Ee] [Tt][Aa][Gg]:[[:space:]]*([^[:space:]]+)[[:space:]]*$/\1/p' "$1"
}

stable_tag=$(read_stable_tag readme.txt)
if [[ ! "$stable_tag" =~ ^[0-9]+(\.[0-9]+)*$ ]]; then
  echo 'Expected one numeric Stable Tag in readme.txt.' >&2
  exit 1
fi

svn_url='https://plugins.svn.wordpress.org/aaa-option-optimizer'
svn_dir=$(mktemp -d)
trap 'rm -rf "$svn_dir"' EXIT

svn checkout --non-interactive --depth empty "$svn_url" "$svn_dir"
svn update --non-interactive --depth empty "$svn_dir/trunk" "$svn_dir/tags"
svn update --non-interactive --depth empty "$svn_dir/tags/$stable_tag"
svn update --non-interactive "$svn_dir/trunk/readme.txt" "$svn_dir/tags/$stable_tag/readme.txt"

for target in "$svn_dir/trunk/readme.txt" "$svn_dir/tags/$stable_tag/readme.txt"; do
  if [[ ! -f "$target" ]] || [[ "$(read_stable_tag "$target")" != "$stable_tag" ]]; then
    echo 'The local Stable Tag must match the published trunk and tag readmes.' >&2
    exit 1
  fi
done

cp readme.txt "$svn_dir/trunk/readme.txt"
cp readme.txt "$svn_dir/tags/$stable_tag/readme.txt"

if [[ -z "$(svn status "$svn_dir")" ]]; then
  echo 'Both published readmes are already up to date.'
  exit 0
fi

svn diff "$svn_dir"
if [[ "${DRY_RUN:-false}" == 'true' ]]; then
  echo 'Dry run: no files were committed.'
  exit 0
fi

: "${SVN_USERNAME:?SVN_USERNAME is required}"
: "${SVN_PASSWORD:?SVN_PASSWORD is required}"
svn_auth=(--username "$SVN_USERNAME" --password "$SVN_PASSWORD" --non-interactive --no-auth-cache)
svn commit "$svn_dir/trunk/readme.txt" "$svn_dir/tags/$stable_tag/readme.txt" \
  --message "Update aaa-option-optimizer readme.txt for $stable_tag" "${svn_auth[@]}"
