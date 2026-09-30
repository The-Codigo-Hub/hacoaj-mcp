# Cómo funciona el MCP y cómo conectarlo a n8n

Guía técnica del plugin **Hacoaj MCP**: qué expone, cómo responde, cómo se conecta desde n8n y qué revisar cuando algo falla.
Para los prompts de los agentes, ver [prompts.md](prompts.md).

---

## 1. Qué es esto, en dos párrafos

El plugin convierte a WordPress en un **servidor MCP** (Model Context Protocol) de **sólo lectura**. MCP es el protocolo que usan los agentes de IA para descubrir y ejecutar "tools" (funciones) de un sistema externo. En lugar de que el bot adivine horarios, le pregunta al sitio.

Concretamente, agrega un endpoint REST a WordPress. No modifica contenido, base de datos ni el tema: sólo lee los `agenda_item`, las taxonomías `actividad` y `sede`, los campos ACF, las noticias y la revista, los normaliza y los devuelve como JSON.

### El recorrido de una consulta

```
Socio en WhatsApp
      │  "¿a qué hora es natación de nenes en Tigre el sábado?"
      ▼
n8n · AI Agent (el modelo decide qué tool usar)
      │  POST JSON-RPC  { "method": "tools/call",
      │                   "params": { "name": "obtener_agenda",
      │                               "arguments": { "texto": "natación",
      │                                              "sede": "tigre",
      │                                              "dia": "sábado" } } }
      │  Authorization: Bearer hmcp_…
      ▼
hacoaj.org.ar/wp-json/hacoaj-mcp/v1/mcp   ← este plugin
      │  1. valida el token
      │  2. arma (o lee de caché, 12 h) el índice de la agenda
      │  3. filtra, parsea horarios y devuelve JSON
      ▼
n8n · AI Agent redacta la respuesta en lenguaje natural con esos datos
```

El modelo **no inventa** los horarios: los recibe ya parseados. El plugin le entrega el texto original de cada horario junto con las franjas estructuradas, para que pueda citar el original si hay ambigüedad.

---

## 2. El endpoint

| | |
|---|---|
| **URL** | `https://hacoaj.org.ar/wp-json/hacoaj-mcp/v1/mcp` |
| **Método** | `POST` únicamente (`GET`/`DELETE` responden 405: no hay stream SSE) |
| **Transporte MCP** | Streamable HTTP, modo stateless con respuestas JSON (no hay sesiones ni `Mcp-Session-Id`) |
| **Protocolo** | JSON-RPC 2.0. Versiones MCP soportadas: `2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05` |
| **Auth** | `Authorization: Bearer <token>` o, si el hosting no pasa ese header, `X-Hacoaj-Token: <token>` |
| **Health check** | `GET https://hacoaj.org.ar/wp-json/hacoaj-mcp/v1/health` — público, sin datos del club |

### Métodos JSON-RPC implementados

| Método | Qué devuelve |
|---|---|
| `initialize` | Versión de protocolo, capabilities, `serverInfo` e `instructions` (una guía corta que el agente puede usar como contexto) |
| `ping` | `{}` |
| `tools/list` | Las 10 tools con su `inputSchema` (JSON Schema) y `annotations.readOnlyHint: true` |
| `tools/call` | El resultado de una tool |
| `resources/list`, `prompts/list` | Listas vacías (este servidor sólo expone tools) |

Las notificaciones (`notifications/initialized`, etc.) se aceptan y responden `202` sin cuerpo. Se admiten batches JSON-RPC.

---

## 3. Probarlo a mano (sin n8n)

**1. ¿Está vivo el endpoint y llega el header `Authorization`?**

```bash
curl -s -H "Authorization: Bearer probe" https://hacoaj.org.ar/wp-json/hacoaj-mcp/v1/health
```

```json
{"ok":true,"plugin":"hacoaj-mcp","version":"1.1.0","token_configurado":true,"authorization_visible":true}
```

- `ok: true` → la REST API responde y Wordfence no bloquea la ruta.
- `authorization_visible: true` → el hosting pasa el header a PHP. Si es `false`, hay que agregar al `.htaccess` `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`, o usar `X-Hacoaj-Token` en n8n.
- `token_configurado: true` → hay token generado.

**2. Listar las tools:**

```bash
curl -s -X POST https://hacoaj.org.ar/wp-json/hacoaj-mcp/v1/mcp \
  -H "Authorization: Bearer $HACOAJ_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

**3. Ejecutar una tool:**

```bash
curl -s -X POST https://hacoaj.org.ar/wp-json/hacoaj-mcp/v1/mcp \
  -H "Authorization: Bearer $HACOAJ_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"obtener_agenda","arguments":{"texto":"natación","sede":"tigre","dia":"sábado"}}}'
```

Desde el servidor, con WP-CLI, se puede probar sin token ni red:

```bash
wp hacoaj-mcp tools
wp hacoaj-mcp call obtener_agenda --args='{"sede":"tigre","dia":"hoy"}'
wp hacoaj-mcp stats
```

---

## 4. Configurar n8n

En el workflow del bot:

1. Nodo **AI Agent** (con su Chat Model y su memoria).
2. Conectarle un nodo **MCP Client Tool**.
3. Configurarlo así:

| Campo | Valor |
|---|---|
| Endpoint | `https://hacoaj.org.ar/wp-json/hacoaj-mcp/v1/mcp` |
| Server Transport | **HTTP Streamable** |
| Authentication | **Bearer Auth** → credencial con el token (`hmcp_…`) |
| Tools to Include | **All** |

Si el hosting no pasa el header `Authorization` (lo dice el health check), en lugar de Bearer Auth usar **Header Auth** con nombre `X-Hacoaj-Token` y el token como valor.

**El token va en una credencial de n8n, nunca escrito en el nodo ni en el prompt.**

Recomendado en el AI Agent:

- **System prompt**: uno de los de [prompts.md](prompts.md).
- **Max iterations**: 5 o más. Una consulta típica encadena dos tools (`buscar_actividades` → `obtener_agenda`).
- La zona horaria del workflow no afecta a `"hoy"`/`"mañana"`: los resuelve el plugin con la hora de Argentina.

---

## 5. Las 10 tools

Todas son de sólo lectura e idempotentes. Los parámetros van en `arguments`; salvo `buscar_en_sitio` (que exige `texto`) ninguno es obligatorio, pero `obtener_agenda` necesita al menos un filtro.

| Tool | Para qué | Parámetros |
|---|---|---|
| `buscar_actividades` | Qué hay para alguien (el punto de entrada habitual) | `texto`, `edad`, `genero`, `sede`, `dia`, `categoria`, `limite` |
| `obtener_agenda` | Horarios concretos | `actividad` (slug), `texto`, `sede`, `dia`, `categoria`, `edad`, `genero`, `desde_hora`, `hasta_hora`, `limite` |
| `listar_sedes` | Direcciones, teléfonos, links | — |
| `listar_categorias` | Árbol de categorías con sus actividades | — |
| `deportes_federados` | Categorías competitivas | `deporte`, `genero`, `edad`, `anio_nacimiento`, `sede`, `dia`, `limite` |
| `transporte` | Micro CABA–Tigre y combi Club de Campo–Marinas H–Tigre | — |
| `info_institucional` | Tikun, Magal, Bitnuah, Distrito Hacoaj | `tema` |
| `noticias` | Últimas noticias del sitio | `texto`, `cantidad` |
| `revista` | Últimos números de la Revista Hacoaj | `cantidad` |
| `buscar_en_sitio` | Búsqueda general en páginas y noticias (último recurso) | `texto` (requerido), `cantidad` |

### Valores que aceptan los parámetros comunes

- **`sede`**: `tigre-maliar`, `club-de-campo`, `ben-gurion` (CABA), `marinas-h`, `isla-hacoaj`. También nombres coloquiales: "Tigre", "Capital", "Club de Campo".
- **`dia`**: `lunes`…`domingo`, `"hoy"`, `"mañana"`, `"fin de semana"`, `"semana"`. Se resuelven con la fecha de Argentina.
- **`edad`**: número, admite decimales (`1.5` = 18 meses).
- **`genero`**: `femenino` o `masculino`. Las actividades mixtas siempre se incluyen.
- **`categoria`**: `escuelas`, `adultos`, `cultura`, `hadraja`, `natacion`, `nauticas`, `recreacion`.
- **`desde_hora` / `hasta_hora`**: `"18:00"`.

### Flujo recomendado para el agente

```
"¿qué hay para mi hija de 6 en Tigre?"   → buscar_actividades { texto/categoria, edad: 6, sede: "tigre" }
"¿y a qué hora?"                          → obtener_agenda { actividad: "<slug del paso anterior>" }
"¿juega al fútbol en serio, federado?"     → deportes_federados { deporte: "fútbol", anio_nacimiento: 2015 }
"¿cómo llego desde Capital?"               → transporte
"¿dirección y teléfono?"                   → listar_sedes
lo que no entra en ninguna (socios, etc.)  → buscar_en_sitio
```

---

## 6. Cómo viene la respuesta

MCP devuelve el resultado como texto; acá ese texto es **el JSON de la tool**:

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "result": {
    "content": [ { "type": "text", "text": "{\"total\":4,\"actividades\":[…]}" } ],
    "isError": false
  }
}
```

n8n se encarga de entregarle ese contenido al modelo. `isError: true` indica un error de la tool (argumentos inválidos, filtros faltantes); el texto es entonces el mensaje de error, en castellano, pensado para que el modelo se corrija solo y reintente.

### Ejemplo real: `buscar_actividades`

Argumentos `{ "texto": "natacion", "edad": 6, "sede": "tigre", "limite": 2 }`:

```json
{
  "total": 4,
  "actividades": [
    {
      "slug": "natacion-ninos-pileta",
      "nombre": "Natación Niños",
      "categoria": "Natación - Pileta",
      "grupo": "escuelas",
      "edades": "Jardín y primaria",
      "sedes": ["Tigre Maliar"],
      "dias": ["Sábado", "Domingo"],
      "horarios_cargados": true,
      "url": "https://hacoaj.org.ar/agenda-por-actividad/?actividad=natacion-ninos-pileta"
    }
  ],
  "aviso": "Se muestran 2 de 4. Refiná con más filtros.",
  "sin_datos_de_edad": {
    "cantidad": 1,
    "ejemplos": ["Natación Pileta Verano"],
    "nota": "Estas actividades no tienen edad cargada y se excluyeron del filtro por edad."
  }
}
```

El campo `slug` es la llave para pedir después los horarios con `obtener_agenda`.

### Ejemplo real: `obtener_agenda`

Argumentos `{ "actividad": "golf-clases" }` (recortado):

```json
{
  "filtros_aplicados": { "actividades": ["golf-clases"] },
  "total": 1,
  "items": [
    {
      "titulo": "Golf (Escuela)",
      "actividades": ["Golf Clases"],
      "sedes": ["Club de Campo"],
      "dias": ["Sábado", "Domingo"],
      "horarios": [
        {
          "texto": "11, 12 y 14 h clases gratuitas",
          "franjas": [
            { "inicio": "11:00", "fin": null },
            { "inicio": "12:00", "fin": null },
            { "inicio": "14:00", "fin": null }
          ]
        }
      ],
      "telefonos": ["11 6972-2060"],
      "notas": ["…"]
    }
  ]
}
```

Cada horario trae el **texto original** tal como está publicado en la web y las **franjas** ya normalizadas a `HH:MM`. Cuando la consulta usa `dia: "hoy"`, la respuesta agrega `fecha_referencia` con la fecha y el día resueltos.

---

## 7. De dónde salen los datos

| Dato | Fuente | Frescura |
|---|---|---|
| Actividades, jerarquía y sedes | Taxonomías `actividad` y `sede` | En vivo (caché 12 h) |
| Días, horarios, lugar, profe, notas, precios | `agenda_item` + campos ACF (`dias`, `detalle`, `hora_inicio`, `hora_fin`, `lugar`, `profe`) | En vivo (caché 12 h) |
| Edades, género, grupos, aliases, federados, programas | `data/catalog.json` del plugin (curado a mano) | Cambia con cada release |
| Noticias, revista, búsqueda general | WordPress | En vivo, sin caché |

**Caché**: el índice se guarda 12 horas en un transient, pero se invalida solo cuando se edita, publica o borra un `agenda_item`, cuando cambian sus metadatos o términos, y en cada update del plugin. En la práctica, un cambio en la agenda se ve en el bot enseguida. Se puede forzar desde *Ajustes → Hacoaj MCP → Vaciar caché de datos* o con `wp hacoaj-mcp cache`.

El parser de horarios cubre hoy el **94,7%** de los items con texto en `detalle` (337 de 356). Los que no se pueden estructurar igual llegan al agente con su texto original.

### Temporadas (verano / regular)

En el sitio conviven los `agenda_item` de la agenda de verano con los de la regular. El plugin usa el **mismo criterio que la web**:

- cada ítem tiene el metadato `agenda_version` (`regular` o `verano`); sin un valor válido cuenta como `regular`;
- la temporada que se publica sale de la opción `hacoaj_agenda_temporada_publica`, resuelta con `ha_resolve_agenda_version( 'auto' )` si esa función existe, o con `ha_auto_agenda_version()` si la opción está en `auto`;
- sólo se indexan los ítems de esa temporada, así que cuando la web cambia de temporada el bot cambia con ella.

En *Ajustes → Hacoaj MCP* se puede forzar `regular`, `verano` o `todas`. Si el sitio no tuviera ese metadato, el plugin cae a un respaldo por fecha de publicación (ignora lo anterior a `2026-02-01`).

También se ocultan los avisos vencidos del tipo "Sin horarios durante enero" o "comienza la semana del 2 de marzo".

---

## 8. Seguridad

- **Sólo lectura**: ninguna tool escribe. No hay forma de crear, editar ni borrar contenido a través del MCP.
- **Token**: 256 bits de entropía, guardado sólo como hash sha256 en `wp_options` (sin autoload). No depende de `wp_salt()`, ni de usuarios, ni de application passwords: sobrevive a updates, desactivaciones y a que Wordfence regenere los salts. La constante `HACOAJ_MCP_TOKEN_HASH` en `wp-config.php` tiene prioridad y lo hace sobrevivir incluso a una restauración de la base.
- **Rate limit**: 20 intentos fallidos cada 10 minutos por IP; 720 requests por minuto con token válido (filtro `hacoaj_mcp_rate_limit_per_minute`).
- **Origin**: si el request trae un header `Origin` que no sea el del propio sitio, se rechaza con 403 (protege contra un navegador ajeno; n8n no manda `Origin`). Ampliable con el filtro `hacoaj_mcp_allowed_origins`.
- **Rotar el token**: *Ajustes → Hacoaj MCP → Rotar token*, o `wp hacoaj-mcp token generate`. Hay que actualizar la credencial en n8n; el token anterior deja de servir.
- El endpoint `/health` es público a propósito, para poder diagnosticar sin credenciales: no devuelve datos del club ni del token.

---

## 9. Diagnóstico

| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| `401` + `WWW-Authenticate: Bearer` | Token ausente, mal copiado o rotado | Revisar la credencial en n8n. Confirmar con `/health` que `token_configurado` sea `true` |
| `401` aunque el token esté bien | El hosting no pasa el header `Authorization` a PHP | Si `/health` devuelve `authorization_visible: false`: agregar `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` al `.htaccess`, o pasar a **Header Auth** con `X-Hacoaj-Token` |
| `403` "Origin no permitido" | El cliente manda un `Origin` de otro dominio | Usar n8n o curl (no un navegador), o ampliar `hacoaj_mcp_allowed_origins` |
| `405` | Se está haciendo `GET` | El MCP es sólo `POST`. En n8n, Server Transport = HTTP Streamable |
| `429` | Rate limit | Esperar unos minutos; si es legítimo, subir el límite con el filtro |
| `503` "no tiene token configurado" | Nunca se generó, o se revocó | *Ajustes → Hacoaj MCP → Generar token* |
| HTML en vez de JSON, o timeout | Wordfence / firewall del hosting | Agregar `/wp-json/hacoaj-mcp/` a la allowlist |
| El bot da horarios viejos | Caché | *Vaciar caché de datos* o `wp hacoaj-mcp cache`. Si persiste, revisar la temporada en Ajustes |
| Falta una actividad entera | Temporada mal resuelta, o falta en el catálogo | *Ajustes → Hacoaj MCP → Datos*: muestra qué criterio de temporada se aplicó, cuántos ítems se excluyeron y qué actividades no tienen metadata |

La pantalla *Ajustes → Hacoaj MCP* concentra casi todo: estado y origen del token, último uso, prueba de loopback del header `Authorization`, versión instalada y última publicada, estadísticas del índice y criterio de temporada aplicado.

---

## 10. Actualizaciones

El plugin se actualiza solo con el mecanismo nativo de WordPress apuntado a los Releases de GitHub:

1. WordPress consulta `api.github.com/repos/The-Codigo-Hub/hacoaj-mcp/releases/latest` (caché 6 h).
2. Si hay versión nueva, descarga `hacoaj-mcp.zip` y la instala por cron (~2 veces por día). Se puede forzar con *Buscar actualizaciones ahora*.
3. WordPress ≥ 6.3 hace rollback automático si un update rompiera el sitio.

Requisitos del hosting: que **no** estén definidas `DISALLOW_FILE_MODS` ni `AUTOMATIC_UPDATER_DISABLED`, que WP-Cron funcione y que las actualizaciones automáticas del plugin queden habilitadas. La pantalla de ajustes avisa si algo de esto falta.
