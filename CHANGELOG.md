# Changelog

## [1.4.2] - 2026-09-30

- Rate limit por token de 240 a 720 requests/min

## [1.4.1] - 2026-09-28

- Fix: actividades con ACF completo se muestran aunque todavia no tengan agenda cargada (tipo_categoria=hija); corrige fatal error en deportes_federados con datos incompletos

## [1.4.0] - 2026-09-28

- Deportes federados leen de WordPress (grupo=federadas) en vez de catalog.json, con recalculo de edad por año de nacimiento

## [1.3.0] - 2026-09-28

- edad, género y grupo de actividades salen exclusivamente del ACF de WordPress (sin respaldo de catalog.json): permite ver en vivo el avance de la migración actividad por actividad

## [1.2.0] - 2026-09-28

- Metadata de actividades (edad, género, grupo) desde ACF de WordPress, con prioridad sobre catalog.json

## [1.1.0] - 2026-09-17

- Temporada de agenda tomada de agenda_version, igual que la web; documentación de n8n y prompts.

- Filtro de temporada por el meta `agenda_version` y la temporada pública del sitio (`hacoaj_agenda_temporada_publica`), el mismo criterio que la web. El filtro por fecha de publicación queda como respaldo para sitios sin ese campo.
- Ajustes: selector de temporada (automática, regular, verano, todas).
- Corrige `publicados_desde = off`, que no desactivaba el filtro por fecha.

## [1.0.0] - 2026-09-15

- Servidor MCP (Streamable HTTP, JSON-RPC) en `/wp-json/hacoaj-mcp/v1/mcp` con 10 tools de sólo lectura.
- Parser de horarios del campo ACF `detalle` (95% de los items con franjas estructuradas).
- Catálogo curado migrado del bot anterior: edades, género, grupos, federados, programas, distrito; sin duplicados.
- Filtro de temporada: ignora la agenda de verano 2026 (publicada antes del 2026-02-01).
- Token Bearer hasheado que sobrevive a updates, reinstalaciones y cambios de salts; override por `wp-config.php`.
- Auto-update desde GitHub Releases, pantalla de ajustes con diagnóstico y comandos WP-CLI.
