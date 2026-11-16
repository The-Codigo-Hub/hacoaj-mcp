#!/usr/bin/env bash
# Arma "Hacoaj MCP Lab": una copia del plugin con otros identificadores para instalarla en el mismo
# WordPress, al lado de la de producción, y probar cambios sin tocar el bot real.
#
#   bin/build-lab.sh 1.5.0-lab.1
#
# Deja build/hacoaj-mcp-lab.zip. Diferencias con el plugin normal:
# - Clases, constantes, opciones, filtros, ruta REST (/wp-json/hacoaj-mcp-lab/v1/mcp) y comando WP-CLI renombrados:
#   no choca con el plugin de producción y tiene su propio token.
# - Sin actualizaciones automáticas: no tiene Update URI de GitHub ni registra el Updater.
set -euo pipefail

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  echo "Uso: bin/build-lab.sh X.Y.Z-lab.N" >&2
  exit 1
fi

cd "$(dirname "$0")/.."
ROOT="$PWD"
OUT="$ROOT/build/hacoaj-mcp-lab"
rm -rf "$OUT" "$ROOT/build/hacoaj-mcp-lab.zip"
mkdir -p "$OUT"

rsync -a --exclude-from=.distignore --exclude build ./ "$OUT/"
mv "$OUT/hacoaj-mcp.php" "$OUT/hacoaj-mcp-lab.php"

find "$OUT" -type f \( -name '*.php' -o -name '*.json' -o -name '*.md' \) -print0 | xargs -0 perl -pi -e '
  s/HacoajMCP(?!Lab)/HacoajMCPLab/g;
  s/HACOAJ_MCP_(?!LAB_)/HACOAJ_MCP_LAB_/g;
  s/hacoaj_mcp(?!_lab)/hacoaj_mcp_lab/g;
  s/hacoaj-mcp(?!-lab)/hacoaj-mcp-lab/g;
  s/Hacoaj MCP(?! Lab)/Hacoaj MCP Lab/g;
'

MAIN="$OUT/hacoaj-mcp-lab.php"
perl -pi -e "s/^(\s*\*\s*Version:\s*).*/\${1}$VERSION/" "$MAIN"
perl -pi -e "s/define\( 'HACOAJ_MCP_LAB_VERSION', '[^']+' \);/define( 'HACOAJ_MCP_LAB_VERSION', '$VERSION' );/" "$MAIN"
perl -pi -e 's/^(\s*\*\s*Update URI:\s*).*/${1}hacoaj-mcp-lab-sin-actualizaciones/' "$MAIN"
perl -pi -e 's/^(\s*\*\s*Description:\s*)/${1}COPIA DE PRUEBA, no la usa el bot de producción. /; s/ Se actualiza solo desde GitHub\.//' "$MAIN"
perl -pi -e 's/^(\s*)\$this->updater->register\(\);/${1}\/\/ Lab: sin actualizaciones automáticas.\n${1}\/\/ \$this->updater->register();/' "$OUT/includes/class-plugin.php"

# El Lab acepta también el token del plugin de producción: una copia del bot en n8n se pasa al Lab
# cambiando solo la URL, con la misma credencial (no hace falta crear ni copiar tokens).
perl -0pi -e 's{(\n\t\treturn \$hashes;\n\t\}\n\n\t/\*\*\n\t \* Extrae el token)}{\n\t\t\$prod = get_option( "hacoaj_mcp" . "_token_hash" );\n\t\tif ( is_string( \$prod ) && preg_match( "/^[a-f0-9]{64}\$/", \$prod ) ) {\n\t\t\t\$hashes[] = \$prod;\n\t\t}\n\t\tif ( defined( "HACOAJ_MCP" . "_TOKEN_HASH" ) && preg_match( "/^[a-f0-9]{64}\$/", (string) constant( "HACOAJ_MCP" . "_TOKEN_HASH" ) ) ) {\n\t\t\t\$hashes[] = constant( "HACOAJ_MCP" . "_TOKEN_HASH" );\n\t\t}$1}' "$OUT/includes/class-auth.php"
grep -q '"hacoaj_mcp" . "_token_hash"' "$OUT/includes/class-auth.php"

# Que no quede ningún identificador del plugin de producción.
if grep -rnE 'HacoajMCP\\|HacoajMCP;|HACOAJ_MCP_[A-Z]|hacoaj_mcp_[a-z]|hacoaj-mcp/' "$OUT" | grep -vE 'HacoajMCPLab|HACOAJ_MCP_LAB_|hacoaj_mcp_lab|hacoaj-mcp-lab' ; then
  echo "Quedaron identificadores sin renombrar." >&2
  exit 1
fi
grep -q 'register();' "$OUT/includes/class-plugin.php" && grep -q '// \$this->updater->register' "$OUT/includes/class-plugin.php"

( cd "$ROOT/build" && zip -qr hacoaj-mcp-lab.zip hacoaj-mcp-lab )
echo "build/hacoaj-mcp-lab.zip ($VERSION)"
