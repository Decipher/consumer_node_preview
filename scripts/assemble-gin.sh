#!/usr/bin/env bash
##
# Post-assemble hook: add the Gin admin theme to the build when it resolves.
#
# NodePreviewThemesTest checks the preview in Gin. Every Gin release needs
# gin_toolbar, and gin_toolbar needs the drupal/toolbar package. Some older
# cores, such as 11.1, do not declare that they provide that package, so
# Composer cannot install Gin with them.
#
# Composer decides. It exits with 2 when it cannot resolve the dependencies.
# Then the build carries on without Gin and the test skips its Gin case. Any
# other failure stops the assemble. The current working directory is the
# project root.

set -u

constraint='^4.1 || ^5'
log="$(mktemp)"
trap 'rm -f "${log}"' EXIT

composer --working-dir=build require --dev --with-all-dependencies --no-interaction "drupal/gin:${constraint}" >"${log}" 2>&1
code=$?

if [ "${code}" -eq 0 ]; then
  echo "[gin] Installed drupal/gin ${constraint}."
  exit 0
fi

if [ "${code}" -ne 2 ]; then
  cat "${log}"
  echo "[gin] Composer failed with exit code ${code}."
  exit "${code}"
fi

# Show why, so a build that loses Gin is not silent.
grep -E '^\s+- ' "${log}" | grep -iE 'gin|toolbar' | head -n 10
message='drupal/gin does not resolve with this core. The build has no Gin, and NodePreviewThemesTest skips its Gin case.'
if [ -n "${GITHUB_ACTIONS:-}" ]; then
  echo "::warning title=Gin not installed::${message}"
else
  echo "[gin] ${message}"
fi
