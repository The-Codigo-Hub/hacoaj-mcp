# Hacoaj MCP

Plugin de WordPress que expone los datos de **hacoaj.org.ar** (actividades, agenda, sedes, deportes federados, transporte, noticias) como un **servidor MCP** de sólo lectura, para usarlo como tool desde **n8n** u otros agentes de IA.

Reemplaza a `/bot/filter.php`: lee la agenda **en vivo** desde WordPress (CPT `agenda_item` + ACF + taxonomías `actividad`/`sede`), estructura los horarios y le suma un catálogo curado con lo que WordPress no tiene (edades, género, federados).

- Endpoint: `https://hacoaj.org.ar/wp-json/hacoaj-mcp/v1/mcp` (MCP Streamable HTTP, JSON-RPC 2.0)
- Auth: `Authorization: Bearer <token>` (o `X-Hacoaj-Token: <token>`)
- Se actualiza solo desde los Releases de este repo.

**Documentación:**

- [docs/n8n.md](docs/n8n.md) — cómo funciona el MCP, el endpoint y el protocolo, las tools con ejemplos reales de respuesta, la configuración en n8n, seguridad y diagnóstico.
- [docs/prompts.md](docs/prompts.md) — prompts de sistema listos para pegar en el AI Agent de n8n.

## Tools

| Tool | Para qué |
|---|---|
| `buscar_actividades` | Qué actividades hay según texto, edad, género, sede, día o categoría |
| `obtener_agenda` | Horarios concretos (franjas HH:MM, lugar, profe, notas, precios, contactos) |
| `listar_sedes` | Dirección, teléfono, descripción y links de cada sede |
| `listar_categorias` | Árbol de categorías con sus actividades |
| `deportes_federados` | Categorías competitivas con edades/años de nacimiento y horarios |
| `transporte` | Micro CABA–Tigre y combi Club de Campo–Marinas H–Tigre |
| `info_institucional` | Programas (Tikun, Magal, Bitnuah) y Distrito Hacoaj |
| `noticias` / `revista` | Últimas publicaciones |
| `buscar_en_sitio` | Búsqueda general en páginas y noticias |

Probar una tool sin n8n: `wp hacoaj-mcp call obtener_agenda --args='{"sede":"tigre","dia":"hoy"}'`

## Instalación (una sola vez)

1. Descargar `hacoaj-mcp.zip` del [último release](https://github.com/The-Codigo-Hub/hacoaj-mcp/releases/latest).
2. WordPress → Plugins → Añadir nuevo → Subir plugin → Activar.
3. **Ajustes → Hacoaj MCP** → *Generar token* → copiarlo (se muestra una sola vez).
4. Revisar el **Diagnóstico** de esa pantalla:
   - Si dice que el hosting no pasa el header `Authorization`, usar `X-Hacoaj-Token` en n8n o agregar al `.htaccess`:
     `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`
   - Si Wordfence bloquea el endpoint, agregar `/wp-json/hacoaj-mcp/` a la allowlist.
5. Opcional (recomendado): pegar en `wp-config.php` la línea `define( 'HACOAJ_MCP_TOKEN_HASH', '…' );` que muestra la pantalla. Así el token sobrevive incluso a una restauración de la base.

## Conectar en n8n

Nodo **AI Agent** → tool **MCP Client Tool**:

- **Endpoint**: `https://hacoaj.org.ar/wp-json/hacoaj-mcp/v1/mcp`
- **Server Transport**: HTTP Streamable
- **Authentication**: Bearer Auth → el token (o Header Auth con `X-Hacoaj-Token`)
- **Tools to Include**: All

Guía detallada, con ejemplos de las respuestas y qué revisar cuando algo falla: [docs/n8n.md](docs/n8n.md). Prompts para el agente: [docs/prompts.md](docs/prompts.md).

## Token: por qué sobrevive a todo

- Se guarda sólo el **hash sha256** en `wp_options` (autoload off). No depende de `wp_salt()`, usuarios ni application passwords.
- No se borra al desactivar ni al actualizar. `uninstall.php` sólo lo borra si se tildó explícitamente en Ajustes.
- La constante `HACOAJ_MCP_TOKEN_HASH` en `wp-config.php` tiene prioridad y convive con el de la base (permite migrar sin corte).
- Rotar: botón *Rotar token* o `wp hacoaj-mcp token generate`.
- Rate limit: 20 intentos fallidos cada 10 min por IP; 240 requests/min con token (filtro `hacoaj_mcp_rate_limit_per_minute`).

## Actualizaciones automáticas

El plugin usa el mecanismo nativo de WordPress (`Update URI` + `update_plugins_github.com`):

1. WordPress consulta `api.github.com/repos/The-Codigo-Hub/hacoaj-mcp/releases/latest` (cache 6 h).
2. Si hay versión nueva, descarga el asset `hacoaj-mcp.zip` y lo instala con el auto-updater de WordPress (cron, ~2 veces por día). Se puede forzar con *Buscar actualizaciones ahora*.
3. WordPress ≥ 6.3 hace rollback automático si el update rompe el sitio.

Requisitos del hosting: que no esté definido `DISALLOW_FILE_MODS` ni `AUTOMATIC_UPDATER_DISABLED` (la pantalla de ajustes avisa). Si el repo pasa a ser privado: `define( 'HACOAJ_MCP_GITHUB_TOKEN', 'github_pat_…' );` con permiso de lectura de *Contents*.

### Publicar una versión

```bash
bin/release.sh 1.0.1 "Descripción del cambio"
```

Corre los tests, sube la versión en `hacoaj-mcp.php` y `CHANGELOG.md`, commitea, taguea y pushea. GitHub Actions (`release.yml`) valida que tag y versión coincidan, vuelve a correr los tests, arma el zip y crea el Release. Los sitios lo instalan solos.

## Datos: de dónde sale cada cosa

| Dato | Fuente |
|---|---|
| Actividades, jerarquía, sedes de cada horario | Taxonomías `actividad` y `sede` (en vivo) |
| Días, horarios, lugar, profe, notas | `agenda_item` + ACF `dias`, `detalle`, `hora_*`, `lugar`, `profe` (en vivo, parseado) |
| Edades, género, grupo, aliases | `data/catalog.json` → `actividades` (clave = slug del término) |
| Defaults por categoría (ej. Deportes Adultos = 18+) | `catalog.json` → `categorias` |
| Dirección/teléfono de sedes | `catalog.json` → `sedes` |
| Deportes federados | `catalog.json` → `federados` (las "Categoría 2015" recalculan la edad cada año) |
| Programas, Distrito | `catalog.json` |
| Noticias, revista, búsqueda | WordPress en vivo |

La caché del índice se invalida sola cuando se edita un `agenda_item` o un término, y en cada update del plugin.

### Temporadas

En el sitio conviven 152 `agenda_item` de la **agenda de verano 2026** (publicados del 8 al 26 de enero) con la agenda regular. El plugin usa el mismo criterio que la web:

- Cada `agenda_item` tiene el meta `agenda_version` (`regular` o `verano`). Sin un valor válido cuenta como `regular`.
- La temporada visible sale de la option del sitio `hacoaj_agenda_temporada_publica`, resuelta con `ha_resolve_agenda_version( 'auto' )` si existe (o `ha_auto_agenda_version()` si la option está en `auto`).
- Sólo se indexan los items de esa temporada. Desde Ajustes se puede forzar `regular`, `verano` o `todas`.

Si el sitio no tiene ese mecanismo, se usa como respaldo la fecha de publicación: se ignoran los publicados antes de `agenda.publicados_desde` del catálogo (`2026-02-01`), configurable en Ajustes.

Además se ocultan avisos vencidos del estilo "Sin horarios durante enero" o "comienza la semana del 2 de marzo 2026".

### Editar el catálogo

1. Editar `data/catalog.json` (las actividades marcadas `"revisar": true` fueron inferidas del texto de la agenda y conviene validarlas).
2. `php tests/run.php` (o `docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/run.php`).
3. `bin/release.sh X.Y.Z "Catálogo: …"`.

La pantalla de ajustes muestra qué actividades de WordPress no tienen metadata en el catálogo.

## Desarrollo

```bash
# Tests sin WordPress (parser, catálogo, índice y consultas contra el snapshot real del sitio)
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/run.php

# WordPress local con los datos del snapshot → http://localhost:8088 (admin/admin)
docker compose -f dev/docker-compose.yml -p hacoajmcp up -d
docker compose -f dev/docker-compose.yml -p hacoajmcp run --rm cli bash /plugin/dev/setup.sh
```

`tests/fixtures/wp-snapshot.json` es una foto de la REST API pública del sitio (2026-09-15). Para refrescarla, volver a bajar `/wp-json/wp/v2/actividad`, `/sede` y `/agenda_item` (con `acf`).

### Estructura

```
hacoaj-mcp.php                 bootstrap + headers (Version, Update URI)
includes/class-mcp-server.php  endpoint REST + JSON-RPC MCP
includes/class-auth.php        token + rate limit
includes/class-tools.php       definición de tools + validación de argumentos
includes/class-repository.php  lectura de WordPress + caché
includes/class-index-builder.php  normalización (sin WP, testeable)
includes/class-query.php       búsquedas y filtros (sin WP, testeable)
includes/class-schedule-parser.php  texto libre de "detalle" → franjas (sin WP)
includes/class-catalog.php     acceso a data/catalog.json
includes/class-updater.php     auto-update desde GitHub Releases
includes/class-settings.php    pantalla de ajustes
includes/class-cli.php         wp hacoaj-mcp …
data/catalog.json              metadata curada
```

Filtros disponibles: `hacoaj_mcp_tools`, `hacoaj_mcp_catalog_path`, `hacoaj_mcp_rate_limit_per_minute`, `hacoaj_mcp_allowed_origins`, `hacoaj_mcp_client_ip`.
