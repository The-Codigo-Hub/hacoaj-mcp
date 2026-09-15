#!/usr/bin/env bash
# Publica una nueva versión: sube la versión, commitea, taguea y pushea.
# GitHub Actions arma el zip y crea el Release; los sitios se actualizan solos (WP chequea cada ~12 h).
#
#   bin/release.sh 1.0.1 "Descripción corta del cambio"
set -euo pipefail

VERSION="${1:-}"
MESSAGE="${2:-}"
if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Uso: bin/release.sh X.Y.Z \"descripción\"" >&2
  exit 1
fi

cd "$(dirname "$0")/.."

if [ -n "$(git status --porcelain)" ]; then
  echo "Hay cambios sin commitear. Commiteá primero." >&2
  exit 1
fi
if git rev-parse "v$VERSION" >/dev/null 2>&1; then
  echo "El tag v$VERSION ya existe." >&2
  exit 1
fi

if command -v php >/dev/null 2>&1; then
  php tests/run.php
elif command -v docker >/dev/null 2>&1; then
  docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/run.php
fi

perl -pi -e "s/^(\s*\*\s*Version:\s*).*/\${1}$VERSION/" hacoaj-mcp.php
perl -pi -e "s/define\( 'HACOAJ_MCP_VERSION', '[^']+' \);/define( 'HACOAJ_MCP_VERSION', '$VERSION' );/" hacoaj-mcp.php

DATE=$(date +%Y-%m-%d)
{
  head -n 2 CHANGELOG.md
  printf '## [%s] - %s\n\n- %s\n\n' "$VERSION" "$DATE" "${MESSAGE:-Cambios varios.}"
  tail -n +3 CHANGELOG.md
} > CHANGELOG.md.tmp && mv CHANGELOG.md.tmp CHANGELOG.md

git add hacoaj-mcp.php CHANGELOG.md
git commit -m "Release v$VERSION"
git tag -a "v$VERSION" -m "v$VERSION"
git push origin HEAD
git push origin "v$VERSION"

echo "Listo. Seguí el build en: https://github.com/The-Codigo-Hub/hacoaj-mcp/actions"
