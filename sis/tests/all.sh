#!/usr/bin/env bash
# Runs every maintained AcadLink test suite. Each suite builds its own throwaway database on the MySQL/MariaDB
# server named in api/config.php and never touches the configured database.
#   bash sis/tests/all.sh            all suites
#   bash sis/tests/all.sh v61-lms    only the suites named
# Not included: run.php (the original v1 suite, written before central accounts and permission groups; its checks
# need rewriting) and http_test.php (needs a running server and the school's own records).
set -u
cd "$(dirname "$0")/../.."

SUITES=(
  record-list grade-scale-parity
  central-accounts curriculum-teachers eligibility grading-restore-identity accounting-workflow e2e_lifecycle portal-enrollment
  v56-features v57-toolbar v58-finance v59-canteen v60-library v61-lms v62-features v63-backup v63-security v63-db-collation workspaces
)
[ "$#" -gt 0 ] && SUITES=("$@")

gh=${GITHUB_ACTIONS:-}
failed=()
for s in "${SUITES[@]}"; do
  [ -n "$gh" ] && echo "::group::$s" || echo "=== $s"
  start=$(date +%s)
  if php "sis/tests/$s.php"; then status=ok; else status=FAILED; failed+=("$s"); fi
  [ -n "$gh" ] && echo "::endgroup::"
  echo "--- $s: $status ($(( $(date +%s) - start ))s)"
  [ "$status" = FAILED ] && [ -n "$gh" ] && echo "::error title=Test suite failed::$s"
done

echo
if [ ${#failed[@]} -eq 0 ]; then echo "All ${#SUITES[@]} suites passed."; exit 0; fi
echo "${#failed[@]} of ${#SUITES[@]} suites failed: ${failed[*]}"
exit 1
