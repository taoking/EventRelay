#!/bin/sh
set -eu

set +e
output=$(vendor/bin/deptrac analyse --config-file=tests/Architecture/deptrac.negative.yaml --no-progress 2>&1)
exit_code=$?
set -e

if [ "$exit_code" -eq 0 ]; then
    printf '%s\n' 'Expected Deptrac to reject the forbidden Domain -> Framework dependency.' >&2
    exit 1
fi

if ! printf '%s\n' "$output" | grep -Fq 'ForbiddenFrameworkDependency' || ! printf '%s\n' "$output" | grep -Fq 'Framework'; then
    printf '%s\n' "$output" >&2
    printf '%s\n' 'Deptrac failed, but did not report the expected forbidden dependency.' >&2
    exit 1
fi

printf '%s\n' "$output"
printf '%s\n' 'Deptrac negative validation: PASS'
