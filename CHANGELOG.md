# Changelog

## [1.0.0] - 2026-09-15

- Servidor MCP (Streamable HTTP, JSON-RPC) en `/wp-json/hacoaj-mcp/v1/mcp` con 10 tools de sólo lectura.
- Parser de horarios del campo ACF `detalle` (95% de los items con franjas estructuradas).
- Catálogo curado migrado del bot anterior: edades, género, grupos, federados, programas, distrito; sin duplicados.
- Filtro de temporada: ignora la agenda de verano 2026 (publicada antes del 2026-02-01).
- Token Bearer hasheado que sobrevive a updates, reinstalaciones y cambios de salts; override por `wp-config.php`.
- Auto-update desde GitHub Releases, pantalla de ajustes con diagnóstico y comandos WP-CLI.
