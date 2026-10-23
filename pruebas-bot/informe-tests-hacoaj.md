# Informe de pruebas del bot de Hacoaj («Lea»): Word de Mica del 22/10

- **Fecha de la corrida:** 23/10/2026, entre las 07:02 y las 07:09 UTC.
- **Workflow:** n8n «Hacoaj 2.1» (`o1xT9OM1z9CvIWry`), versión del 22/10 a las 23:42 UTC. Modelo gpt-5.1 con temperatura 0.2.
- **Cómo se probó:** POST a `https://n8n.grupocesa.dev/webhook/hacoaj`, un `chatId` por caso (`test-claude-20261023-<caso>`) y 2 segundos entre mensajes. Script: [correr_casos.py](correr_casos.py).
- **Alcance:** solo lectura. No se modificó nada y no se probaron reclamos, invitaciones ni fiestas.

> **Nota sobre el caso 01.** Una primera prueba del script usó el mismo chatId (`…-01`), así que el caso 01 de la batería arrancó con memoria. Lo repetí con un chatId limpio (`…-01r`, ejecución 1262259) y la evaluación usa esa repetición. La ejecución contaminada (1262232) queda en `resultados/caso-01.json` como referencia.

## 1. Resumen

| Resultado | Casos | Cuáles |
|---|---|---|
| ✅ OK | 7 | 03, 04, 07, 09, 10, 11, 13 |
| 🟡 Parcial | 3 | 01, 02, 12 |
| ❌ No OK | 4 | 05, 06, 08, 14 |

Los chequeos automáticos (A1 a A7) pasan en las 26 respuestas. El largo máximo fue de **983 caracteres** (caso 03, mensaje 1) y ninguna respuesta pasó los 1000. Ninguna termina cortada.

Las fallas reales son de **criterio**:

- Se pierde la edad: pide el año aunque ya tiene la edad.
- Infiere el género con «hijo/a».
- Hay un dato de género mal cargado en WordPress.
- El listado de federadas es inconsistente: un turno lista 12 y otro resume.
- Faltan actividades en el listado de cultura.
- «Vaciar chat» distingue mayúsculas.

## 2. Tabla por caso

| Caso | Mensaje | Resultado | Qué falló (citando el texto) | Ejecución n8n | Dónde se corrige |
|---|---|---|---|---|---|
| 01 | `Tiene básquet el club` | 🟡 Parcial | Bloque 🎽 bien, sin datos al lado del nombre. Federadas resumidas, pero: (a) el total es vago, «Hay más de 20 categorías en total» (son 22: 18 caballeros y 4 damas); (b) los ejemplos no están agrupados por género, «masculino y femenino en muchas categorías (por ejemplo Mosquitos, Premini, Mini, Infantiles, Cadetes, Mayores, etc.)»; (c) «Básquet Femenino» sale sin «(damas)». Pide la edad ✔. | 1262259 (repetición); 1262232 (contaminada) | Prompt (formato del resumen) · Plugin (`aviso`) |
| 02 | `Estoy buscando información de basquet` | 🟡 Parcial | No resume: lista **12 federadas** (8 masculinas y 4 femeninas) y cierra con «Hay más categorías federadas además de estas», sin el total (22). La estructura **no es la misma que en el 01**, que resumió. Acá sí puso «Básquet Femenino (damas)». La causa es que el `aviso` del plugin dice «mostrá estas, decí cuántas más hay», y eso contradice la regla del prompt para más de 8 categorías. Este turno siguió al plugin. | 1262233 | Plugin (`aviso` de `deportes_federados`) · Prompt |
| 03 | `Tiene básquet el club` → `Básquet Jardín` | ✅ OK | Mensaje 1: igual al 02 (lista las 12 federadas, mismo problema). Mensaje 2: «Ben Gurión (CABA), miércoles y viernes, 18.30 a 19.30 h — 2° Subsuelo» y «Tigre Maliar, sábados y domingos, 13.30 a 14.30 h — Playón Básquet», que coincide con la agenda. Cierra con «¿En qué sede te interesaría anotarlo? Si querés, te puedo derivar con el área de Deportes…» ✔. | 1262236, 1262237 | — |
| 04 | `Quiero info de hockey` | ✅ OK | Nombres sin edades ni sede. «Hockey Primaria» aparece una sola vez (el plugin agrupa las dos sedes en `variantes`). Federadas: «Hay 19 categorías… (por ejemplo 9na, 8va, 7ma y 6ta división femeninas)». Las 19 son femeninas según la herramienta, así que los ejemplos de un solo género están bien. Ninguna federada mezclada en 🎽. Para revisar: «Hockey 10ma» (8 años) figura en WordPress como Escuela Deportiva. Hay que confirmar con Mica si es una escuela o una división. | 1262238 | (Datos WP: confirmar «Hockey 10ma») |
| 05 | `Quiero anotar a mi hijo en fútbol` | ❌ No OK | Aparece **«Futbol Recreativo Femenino Adultas»** en Deportes Adultos aunque el pedido era masculino. La herramienta recibió `genero: "masculino"`, pero WordPress tiene esa actividad (slug `futbol-recreativo-adultos`) con `genero: "mixto"`, así que el filtro la deja pasar. Además, el bot **omitió «Fútbol Sala de 5»**, que la herramienta sí devolvió. Federadas bien: «Hay al menos 15 categorías de Fútbol Federado masculino» con ejemplos (son exactamente 15, así que «al menos» sobra). Nombres sin sede ✔. No se corta ✔. | 1262239 | **Datos de WordPress** (género) · Prompt (ítem omitido) |
| 06 | 1. `Quiero información sobre cultura` | ❌ (msg 1) | La lista de cultura **omite Zumba, Coreo Kids y Bitnuah** («🎭 Teatro, 💃 Rikudim… 🎉 Eventos especiales»). No llamó a ninguna herramienta: la arma de memoria a partir de la lista del prompt. | 1262240 | Prompt |
| 06 | 2. `gracias te consulto por fútbol` | ✅ (msg 2) | Muestra **los dos bloques**: 🎽 con Escuelas Deportivas y Deportes Adultos, y 🏅 con «Hay 20 categorías de Fútbol Federado (masculino y femenino)» y ejemplos agrupados (primero masculino, después femenino). Cierra con «¿Te interesa algo recreativo/escuela o competitivo (federado)?». El error de la imagen 3 no se repite. | 1262241 | — |
| 06 | 3. `es para mi hijo de 10` | ❌ (msg 3) | **No muestra nada y pide el año**: «Para poder decirte exactamente cuáles le corresponden (sobre todo en federado), necesito el año de nacimiento. ¿En qué año nació tu hijo?». No llamó a ninguna herramienta. Tendría que haber llamado a ambas con `edad: 10` y `genero: masculino`, y mostrar la Categoría 2016 masculino. | 1262242 | **Prompt** |
| 06 | 4. `y para mi hijo de 18` | ❌ (msg 4) | Mismo problema: «necesito el año de nacimiento de cada hijo. ¿En qué año nació tu hijo de 10 y en qué año nació tu hijo de 18?». Sin herramientas. No llega a mostrar Fútbol Recreativo +18 ni las Primeras masculinas. (No apareció «Primera LUEFI femenino», pero solo porque no mostró nada). | 1262243 | **Prompt** |
| 07 | `básquet para mi hija de 12` | ✅ OK | Llamó a las dos herramientas con `edad: 12, genero: femenino`. Muestra solo «Básquet Federado - Infantil femenino» y «no encontré actividades recreativas de básquet para 12 años»: `buscar_actividades` devolvió 0, porque el básquet de escuela es hasta los 9 años y el de adultos desde los 18. Ninguna categoría masculina ✔. | 1262244 | — |
| 08 | `fútbol para mi hijo/a de 10` | ❌ No OK | **Infirió el género masculino con «hijo/a»**: las dos herramientas recibieron `genero: "masculino"`. Solo muestra «Fútbol Federado - Categoría 2016 masculino» y **falta «Sub 10 femenino»**. Además cierra con «el nivel de tu hijo». El prompt (línea 136) dice explícitamente que con «hijo/a» no tiene que inferir. | 1262245 | **Prompt** |
| 09 | `Me pasas info de gimnasia artistica` → `Mi hija tiene 10` → `sí` | ✅ OK | Mensaje 1: el texto de la Regla 18 tal cual, con la derivación ofrecida y `context: search`. Mensaje 2: no pide de nuevo la edad, mantiene la explicación y vuelve a ofrecer la derivación. Mensaje 3: «¡Genial! Te conecto con alguien del área de Deportes para que te asesoren 🙌», con `context: agent` y `continue: false` ✔. **Falta el área:** la respuesta no trae ningún campo que diga «Deportes», así que HiBot no puede mandarlo a esa campaña. | 1262246, 1262247, 1262248 | HiBot / n8n (campo de área) |
| 10 | `Quiero saber el valor de las cuotas` | ✅ OK | Usa la fila «ASOCIACIÓN Y CUOTA» (row 2) del Excel: «contactarte directamente con el Departamento de Socios… sociosdepartamento@hacoaj.org.ar… también puedo derivarte». Sin montos ✔. En la ejecución, «PREGUNTAS FRECUENTES1» devolvió las filas, no 0 ✔. | 1262249 | — |
| 11 | `cómo hago para invitar a alguien al club` | ✅ OK (entró al flujo) | **Entró al flujo de invitaciones**, no a la fila INVITACIONES del Excel: «¿De qué tipo de invitación se trata? 1) Invitación regular 2) Invitación de cumpleaños». No llamó a ninguna herramienta (ni preguntas frecuentes ni las de invitaciones) y todavía no pedía datos del invitado. Corté el caso ahí. Observación: no ofrece el mail `INVITACIONES@HACOAJ.ORG.AR` ni el link al reglamento que trae la fila del Excel. | 1262250 | (Prompt, opcional) |
| 12 | 1. `Quiero información sobre cultura` | 🟡 (msg 1) | La lista está completa, con nombres enteros («Curso de Arte», «Grupo Vocal», «Talleres de Cine») y con Bitnuah. No pide edad ✔. Pero arranca con «Te cuento: las actividades culturales no requieren edad, y para darte info bien concreta necesito saber cuál te interesa» y dice «entre otras», que sugiere una lista incompleta. Es distinta de la del 06.1 (no hay herramienta, la lista se improvisa). | 1262251 | Prompt |
| 12 | 2. `Quiero info de teatro` | ✅ (msg 2) | «Tigre Maliar · 2° y 4° sábado de cada mes · 15:00 a 16:30 h · Sala 5 – 1° piso Edificio Central», que coincide con lo esperado. Cierra con «¿Querés que te derive con alguien de Cultura para anotarte…?» ✔. | 1262252 | — |
| 12 | 3. `Cómo me puedo anotar? Puedo hablar con alguien` | 🟡 (msg 3) | `context: agent` y `continue: false` ✔, pero el texto no es el de la Regla 19: «Te voy a conectar con alguien **del equipo de Hacoaj**» en lugar de «…del área de Cultura…». **Falta el campo de área** para la campaña «Cultura» de HiBot. | 1262253 | Prompt · HiBot / n8n (área) |
| 13 | `quiero info de natación` → `y de vóley?` | ✅ OK | Pasa A1 y A2 en los dos (558 y 433 caracteres). Natación: 11 ítems y «Por ahora no hay natación federada publicada». Vóley: «Hay 18 categorías de Voley Federado…», resume y pregunta. Detalles: en natación hay nombres casi duplicados («Natación Niños» y «Natación niños y adolescentes»), y en vóley el subtítulo mezcla las categorías: «🏐 *Escuelas Deportivas / Adultos*». | 1262254, 1262255 | (Datos WP · Prompt, menores) |
| 14a | `vaciar chat` | ✅ | `{"context":"end","text":"La memoria del chat ha sido eliminada","continue":false}`. | 1262256 | — |
| 14b | `VACIAR CHAT` | ❌ | Va al agente: «no tengo forma de borrar el historial de tu lado… podés cerrar esta conversación y abrir una nueva». `context: search`, `continue: true`. **No borra la memoria.** | 1262257 | **n8n** |
| 14c | `Vaciar chat` | ❌ | Lo mismo: «no tengo forma de borrar el historial del chat en tu dispositivo…». Confirmado: el nodo `Need to Clean Memory1` tiene `caseSensitive: true` y `equals "vaciar chat"`. | 1262258 | **n8n** |

## 3. Chequeos automáticos

| Chequeo | Fallas | Comentario |
|---|---|---|
| A1, largo ≤ 1024 | 0 | Máximo: 983 (03.1). Siguen: 981 (02.1), 922 (01.1 contaminada) y 825 (06.2). Ninguna pasó los 1000. Las respuestas que listan 12 federadas (02 y 03.1) quedan a unos 40 caracteres del límite, así que un nombre largo más las cortaría. |
| A2, última línea completa | 0 | Ninguna termina en «Fed», «Pr» ni a mitad de palabra. |
| A3, JSON válido | 0 | Las 26 respuestas parsean y traen `text`, `context` y `continue`. |
| A4, sin «Actividades Generales» | 0 | |
| A5, sin datos al lado del nombre | 0 | El único texto agregado al lado de un nombre fue «(caballeros)» o «(damas)», que está permitido. El 08 muestra la sede y los horarios de la única federada, pero fuera del bloque 🎽. |
| A6, sin precios | 0 | |
| A7, sin campos internos | 0 | Hubo un falso positivo en 09.2 («la edad no define el grupo: se organiza…»). Ajusté el regex para que solo marque `campo: valor` de la API. |

## 4. Fallas agrupadas por dónde se corrigen (propuestas, sin aplicar)

### Prompt (system message del nodo «AI Agent1»)

1. **La edad que llega en un seguimiento se descarta y pide el año (06.3 y 06.4).**
   - **Causa:** la sección «CLASIFICACIÓN POR AÑO DE NACIMIENTO» (líneas 106 a 112) dice que el dato que se pide es el año y que «convertir una edad… puede mandar a la persona a una categoría equivocada». Además, la excepción de la línea 112 dice «Solo si el usuario pregunta específicamente por la categoría federada… pedile… el año». El modelo cita esa misma justificación («sobre todo en federado»).
   - **Propuesta:**
     - Borrar la excepción de la línea 112, o restringirla a «si el usuario pregunta literalmente "¿en qué categoría va?"».
     - Agregar una regla: «Si el mensaje trae solo edad o parentesco ("es para mi hijo de 10", "y para mi hijo de 18") y hay una ACTIVIDAD ACTIVA, llamá a buscar_actividades { texto: actividad activa, edad, genero } y a deportes_federados { deporte, edad, genero } y mostrá el listado. NUNCA pidas el año si ya tenés la edad».
     - Sumar este mismo diálogo del Word como EJEMPLO CRÍTICO (✅ y ❌).
2. **Infiere el género con «hijo/a» (08).**
   - La regla existe (línea 136), pero está debajo de la lista de inferencia (líneas 129 y 130) y el modelo matchea «hijo» primero.
   - **Propuesta:** moverla adentro de la lista, como primera línea: «neutro → "hijo/a", "hijos", "mi hijo o hija", "mi peque" → NO pasar genero». Agregar el ejemplo: «"fútbol para mi hijo/a de 10" → deportes_federados { deporte: "futbol", edad: 10 } (sin genero) → mostrar Categoría 2016 masculino y Sub 10 femenino agrupados».
3. **El resumen de federadas es inconsistente (01, 02 y 03).** El mismo pedido a veces lista 12 categorías y a veces resume con un total vago («más de 20»).
   - **Propuesta:** fijar una plantilla exacta para más de 8 categorías: «Hay {total} categorías de {deporte} federado ({n} de caballeros y {m} de damas). Por ejemplo: caballeros: A, B, C · damas: X, Y. ¿Para qué edad, año de nacimiento o género buscás?». Indicar que `total` y `total_por_genero` se toman tal cual de la herramienta. Esto va junto con el arreglo del plugin (ver abajo).
4. **La lista de culturales se improvisa (06.1 y 12.1).** El prompt solo tiene una lista «Nombres reconocidos y sus slugs» con nombres abreviados («Vocal», «Cine», «Encuentro»). En 06.1 faltaron Zumba, Coreo Kids y Bitnuah.
   - **Propuesta:** agregar un bloque «LISTADO PARA MOSTRAR (copiar completo, en este orden, sin "entre otras")» con los 12 nombres enteros. O, mejor, obtenerla de WordPress con `buscar_actividades { categoria: "cultura" }` para que no quede desactualizada.
5. **El texto de la derivación a Cultura no es el de la Regla 19 (12.3).**
   - **Propuesta:** en la Regla 19, marcar como OBLIGATORIO el texto «¡Genial! Te conecto con alguien del área de Cultura para que te ayude 🙌» cuando la persona pregunta cómo anotarse o pide hablar con alguien.
6. **«(damas)» y «(caballeros)» aparecen a veces sí y a veces no (01 vs 02).**
   - **Propuesta:** regla explícita: «En Deportes Adultos, si genero = femenino agregá "(damas)"; si genero = masculino, "(caballeros)"; si es mixto, nada».
7. **Omite ítems que devolvió la herramienta (05: «Fútbol Sala de 5»).** Reforzar el «Mostrá TODAS las actividades que devuelva buscar_actividades» con un chequeo: «contá los ítems de la herramienta y los del bloque 🎽: tienen que coincidir».
8. **Menores:**
   - Subtítulos mezclados («Escuelas Deportivas / Adultos» en vóley): respetar un subtítulo por categoría.
   - El 11 no ofrece el mail ni el reglamento de la fila INVITACIONES: si se quiere, que muestre esa info antes de pedir el tipo de invitación.

### Plugin (este repo)

1. **El `aviso` de `deportes_federados` contradice el prompt** ([includes/class-query.php:813](../includes/class-query.php)).
   - Hoy dice «Hay más: **mostrá estas**, decí cuántas más hay…», y eso lleva a listar 12 (02 y 03), mientras el prompt pide resumir cuando hay más de 8.
   - **Propuesta:** cambiar el texto a algo como «Son {total} categorías ({detalle por género}). No las listes: decí el total, nombrá 2 o 3 ejemplos de cada género (primero caballeros, después damas) y preguntá edad, año de nacimiento o género».
   - Alternativa: cuando no hay filtros de edad ni género, devolver `ejemplos_por_genero` (2 o 3 por género) en vez de 12 filas completas, que además achica el prompt.
   - Revisar también la instrucción general de [class-mcp-server.php:198](../includes/class-mcp-server.php), para que diga lo mismo.
2. **El campo `categoria` trae la sede** («Escuelas Deportivas (Tigre Maliar y Ben Gurión)»).
   - El modelo hoy la limpia, pero es un riesgo para A5 (sede entre paréntesis en el subtítulo).
   - **Propuesta:** devolver `categoria` sin el paréntesis. Ya existe la función que lo hace, en [class-query.php:418](../includes/class-query.php), pero se usa solo cuando no hay agenda cargada.

### Datos de WordPress

1. **`futbol-recreativo-adultos` («Futbol Recreativo Femenino Adultas») tiene `genero: mixto`.** Por eso aparece cuando el pedido es para «mi hijo» (05). Cargar `femenino`. De paso, el slug no dice «femenino», lo que confunde.
2. **`futbol-femenino-primaria-escuelas-deportivas-club-de-campo` también tiene `genero: mixto`.** Cargar `femenino`. Hoy el modelo la saca por el nombre, pero el filtro no.
3. **Faltan tildes:** «Basquet Jardín», «Basquet Primaria», «Basquet Primaria CdC» y «Futbol Recreativo Femenino Adultas». Corregirlas mejora la presentación y que el listado coincida con lo que escribe la gente.
4. **«Hockey 10ma» (8 años) como Escuela Deportiva:** confirmar con Mica si es una escuela o una división federada.
5. **Natación:** «Natación Niños» y «Natación niños y adolescentes» parecen duplicadas. Revisar si son dos actividades distintas.

### n8n

1. **«Vaciar chat» distingue mayúsculas (14b y 14c).**
   - En el nodo `Need to Clean Memory1`, poner `caseSensitive: false` en las opciones de la condición.
   - Cambiar el `leftValue` a `{{ $('Webhook').first().json.body.msg.trim().toLowerCase() }}`, para cubrir también los espacios y un punto final, por ejemplo con `.replace(/[.!¡]+$/,'')`.
2. **No hay corte por largo antes de responder.**
   - `Parse For Hibot1` pasa el texto tal cual. Hoy no hizo falta (máximo 983), pero los listados de 12 federadas quedan a unos 40 caracteres del límite.
   - **Propuesta:** en `Parse For Hibot1`, si `text.length > 1024`, cortar en el último salto de línea antes de los 980 caracteres y agregar «Hay más opciones — contame qué te interesa puntualmente 😊».
   - De paso, sacar el `data` suelto al final de `return data;data`. No rompe nada, pero es código muerto.
3. **No hay campo de área para HiBot (09 y 12).**
   - Agregar `area` al JSON de salida (`"deportes" | "cultura" | "socios" | …`): en el prompt, dentro de las respuestas con `context: "agent"`, y en `Parse For Hibot1`, con un default `null`.
   - La hoja de PREGUNTAS FRECUENTES ya tiene una columna **CAMPAÑA** («Derivacion a SOCIOS…») que se puede usar para las derivaciones que salen del Excel.

### HiBot (no se ve por webhook: probar en WhatsApp)

1. **Derivación a campañas:** cuando exista el campo `area`, mapearlo a las campañas «Deportes», «Cultura» y «Socios». Probar en WhatsApp con los casos 09 y 12.
2. **Cortes de mensajes en el canal:** confirmar en WhatsApp que las respuestas de unos 980 caracteres (02 y 03.1) llegan enteras y que HiBot no las parte ni las corta.

## 5. Archivos

- `resultados/caso-XX.json`: cada caso con el chatId, la hora de cada mensaje, la respuesta HTTP cruda, la respuesta parseada y los chequeos A1 a A7.
- `resultados/caso-01r.json`: la repetición del caso 01 con memoria limpia.
- `resultados/resumen.json` y `resultados/log.txt`: un resumen por turno.
- Para recalcular los chequeos sin volver a llamar al bot: `python3 correr_casos.py --rechequear`.

## 6. Repetición después del plugin 1.4.7 (23/10, 07:41 UTC)

El sitio confirmó `"version":"1.4.7"` en `/health`. Repetí los casos 01 a 05 con chatIds nuevos (`test-claude-20261023-<caso>v2`). Las respuestas están en `resultados/caso-XXv2.json`. Los chequeos A1 a A7 pasan en todas: el largo máximo bajó de 983 a 632 caracteres.

| Caso | Antes (1.4.6) | Ahora (1.4.7) | Ejecución |
|---|---|---|---|
| 01 | Resumen vago: «Hay más de 20 categorías», con los ejemplos mezclados. | ✅ «Hay 22 categorías de Básquet Federado (18 masculino y 4 femenino)», con ejemplos agrupados: «Masculino: Mosquitos, Cadetes Celeste (Flex), Primera H» y «Femenino: Mini femenino, Cadetas femenino, Mayor femenino». | 1262266 |
| 02 | Listaba 12 de 22 categorías, sin el total. | ❌ **Regresión del prompt, no del plugin:** «Para poder mostrarte las actividades disponibles necesito saber el año de nacimiento…». No llamó a ninguna herramienta: aplicó la rama de «sin actividad mencionada» aunque el mensaje dice «basquet». La primera corrida del mismo mensaje sí listó, así que es intermitente. | 1262267 |
| 03 | Listaba 12 de 22 categorías. | ✅ El mismo resumen del 01. Mensaje 2 igual que antes: los horarios coinciden y ofrece derivar con Deportes. | 1262268, 1262269 |
| 04 | ✅ 19 femeninas con ejemplos. | ✅ «Hay 19 categorías de hockey federado femenino (por ejemplo: 9na división A, 7ma división C, 4ta división)». Ahora los ejemplos cubren el rango completo. | 1262270 |
| 05 | ❌ Aparecía «Futbol Recreativo Femenino Adultas». | 🟡 Federadas bien: «Hay 15 categorías de Fútbol Federado masculino» con 3 ejemplos. Ya no aparece la femenina, pero **porque el modelo omitió todo el bloque de Deportes Adultos**, incluido «Fútbol Recreativo +18 (caballeros)», y también «Fútbol Sala de 5». La herramienta devolvió los 7 ítems y la femenina sigue con `genero: mixto` en WordPress. | 1262271 |

**Conclusión:** el cambio del plugin resolvió el listado de federadas: ahora siempre da el total por género, ejemplos de los dos géneros y nunca lista las 22. Siguen pendientes:

- **Prompt:**
  - El 02 a veces pide el año aunque se mencione la actividad: reforzar la «REGLA DE ORDEN DE EVALUACIÓN» con este mensaje como ejemplo ❌.
  - El 05 omite ítems devueltos: punto 7 de la sección 4.
- **Datos de WordPress:** el género de `futbol-recreativo-adultos` (punto 1).
