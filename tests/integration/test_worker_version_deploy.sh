#!/usr/bin/env bash
# SPDX-License-Identifier: LGPL-3.0-only
set -euo pipefail
root=$(cd "$(dirname "$0")/../.." && pwd)
fixture=$(mktemp -d /var/tmp/pxe-version-deploy-XXXXXXXX)
trap 'rm -rf -- "$fixture"' EXIT
mkdir "$fixture/assets"
cat > "$fixture/wrangler" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$CALL_LOG"
if [[ "${FAIL_UPLOAD:-0}" == 1 && "$1 $2" == 'versions upload' ]]; then exit 1; fi
MOCK
chmod +x "$fixture/wrangler"
export CALL_LOG="$fixture/log" GITHUB_RUN_ID=123456 GITHUB_RUN_ATTEMPT=1
cd "$root"
bash packaging/ci/deploy-worker-version.sh "$fixture/wrangler" "$fixture/assets"
test "$(wc -l < "$CALL_LOG" | tr -d ' ')" = 2
grep -Fx "versions upload --config packaging/cloudflare/wrangler.jsonc --assets $fixture/assets --tag apt-123456-1" "$CALL_LOG" >/dev/null
grep -Fx 'versions deploy --config packaging/cloudflare/wrangler.jsonc --version-tag apt-123456-1@100% --yes' "$CALL_LOG" >/dev/null
: > "$CALL_LOG"
if FAIL_UPLOAD=1 bash packaging/ci/deploy-worker-version.sh "$fixture/wrangler" "$fixture/assets"; then
    echo 'Failed upload unexpectedly deployed a version' >&2; exit 1
fi
test "$(wc -l < "$CALL_LOG" | tr -d ' ')" = 1
: > "$CALL_LOG"
if GITHUB_RUN_ID=bad bash packaging/ci/deploy-worker-version.sh "$fixture/wrangler" "$fixture/assets"; then
    echo 'Invalid run identifier unexpectedly accepted' >&2; exit 1
fi
test ! -s "$CALL_LOG"
echo 'Version upload/deploy ordering and failures verified without Cloudflare.'
