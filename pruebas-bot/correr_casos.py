#!/usr/bin/env python3
"""Batería end-to-end del bot de WhatsApp de Hacoaj («Lea») contra el webhook de n8n.

Casos del Word «Hacoaj - Ajustes a revisión» (Micaela Acevedo, 22/10).
Solo lectura: no ejerce reclamos, invitaciones ni fiestas.

Uso:  python3 correr_casos.py [caso ...]      (sin argumentos corre todos)
Guarda cada caso en resultados/caso-XX.json y un resumen en resultados/resumen.json.
"""
import datetime as dt
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

WEBHOOK = "https://n8n.grupocesa.dev/webhook/hacoaj"
FECHA = dt.date.today().strftime("%Y%m%d")
PAUSA = 2  # segundos entre mensajes
SALIDA = Path(__file__).parent / "resultados"

CASOS = {
    "01": ["Tiene básquet el club"],
    "02": ["Estoy buscando información de basquet"],
    "03": ["Tiene básquet el club", "Básquet Jardín"],
    "04": ["Quiero info de hockey"],
    "05": ["Quiero anotar a mi hijo en fútbol"],
    "06": [
        "Quiero información sobre cultura",
        "gracias te consulto por fútbol",
        "es para mi hijo de 10",
        "y para mi hijo de 18",
    ],
    "07": ["básquet para mi hija de 12"],
    "08": ["fútbol para mi hijo/a de 10"],
    "09": ["Me pasas info de gimnasia artistica", "Mi hija tiene 10", "sí"],
    "10": ["Quiero saber el valor de las cuotas"],
    "11": ["cómo hago para invitar a alguien al club"],
    "12": [
        "Quiero información sobre cultura",
        "Quiero info de teatro",
        "Cómo me puedo anotar? Puedo hablar con alguien",
    ],
    "13": ["quiero info de natación", "y de vóley?"],
    # Cada «vaciar chat» va con su propio chatId (sufijo a/b/c).
    "14a": ["vaciar chat"],
    "14b": ["VACIAR CHAT"],
    "14c": ["Vaciar chat"],
}

SEDES = r"Tigre Maliar|Ben Guri[oó]n|Club de Campo|Isla|Marinas"
DIAS = r"lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo"
VINETA = re.compile(r"^\s*(?:[-•·▪◦]|\d+[.)])\s+")
TRUNCADOS = re.compile(r"(Fed|Pr|Categor|Femen|Mascul|Federad[oa]?)\s*$")


def llamar(chat_id, msg):
    cuerpo = json.dumps({"chatId": chat_id, "msg": msg}).encode()
    req = urllib.request.Request(
        WEBHOOK,
        data=cuerpo,
        # Cloudflare rechaza el User-Agent por defecto de urllib (error 1010).
        headers={"Content-Type": "application/json", "User-Agent": "curl/8.7.1"},
        method="POST",
    )
    inicio = dt.datetime.now(dt.timezone.utc)
    t0 = time.monotonic()
    try:
        with urllib.request.urlopen(req, timeout=240) as r:
            status, crudo = r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        status, crudo = e.code, e.read().decode("utf-8", "replace")
    except Exception as e:  # timeout, red
        status, crudo = None, f"ERROR: {e!r}"
    return {
        "chatId": chat_id,
        "msg": msg,
        "inicio_utc": inicio.isoformat(),
        "segundos": round(time.monotonic() - t0, 1),
        "http_status": status,
        "crudo": crudo,
    }


def bloque_actividades(texto):
    """Líneas entre «🎽» y «🏅» (o el final)."""
    lineas, dentro = [], False
    for ln in texto.splitlines():
        if "🎽" in ln:
            dentro = True
            continue
        if "🏅" in ln:
            break
        if dentro:
            lineas.append(ln)
    return lineas


def chequear(turno):
    ch = {}
    try:
        data = json.loads(turno["crudo"])
        if isinstance(data, list) and data:
            data = data[0]
        ok = isinstance(data, dict) and all(k in data for k in ("text", "context", "continue"))
    except (json.JSONDecodeError, TypeError):
        data, ok = None, False
    ch["A3_json"] = {"ok": ok}
    turno["respuesta"] = data
    if not ok:
        return ch
    texto = data["text"] or ""
    n = len(texto)
    ch["A1_largo"] = {"ok": n <= 1024, "largo": n, "riesgo": n > 1000}

    ultima = texto.rstrip().splitlines()[-1].strip() if texto.strip() else ""
    corte = bool(TRUNCADOS.search(ultima)) or (
        ultima and ultima[-1].isalnum() and VINETA.match(ultima) is None and len(ultima.split()[-1]) <= 3
    )
    ch["A2_ultima_linea"] = {"ok": not corte, "ultima": ultima}

    ch["A4_generales"] = {"ok": "Generales" not in texto}

    malas = []
    for ln in bloque_actividades(texto):
        if not VINETA.match(ln):
            continue
        limpio = re.sub(r"\((damas|caballeros)\)", "", ln, flags=re.I)
        if (
            re.search(r"\(\d+ a \d+ años", limpio)
            or re.search(r"\(desde \d+", limpio)
            or re.search(rf"\([^)]*({SEDES})[^)]*\)", limpio)
            or re.search(rf" [—–-] .*({DIAS})", limpio, flags=re.I)
        ):
            malas.append(ln.strip())
    ch["A5_datos_al_lado"] = {"ok": not malas, "lineas": malas}

    montos = re.findall(r"\$\s?\d[\d.,]*|\b\d{1,3}(?:\.\d{3})+(?:,\d+)?\b", texto)
    ch["A6_precios"] = {"ok": "$" not in texto and not montos, "montos": montos}

    # Campo interno = nombre del campo seguido de un valor de la API, no prosa
    # como «la edad no define el grupo: se organiza…».
    internos = re.findall(
        r"\b(?:grupo|categoria|genero)\s*:\s*\"?(?:escuelas|generales|federad\w*|masculino|femenino|mixto|cultura|adultos)"
        r"|\bslug\b|needs_review",
        texto,
        flags=re.I,
    )
    ch["A7_internos"] = {"ok": not internos, "encontrados": internos}
    return ch


def correr(caso, mensajes):
    # SUFIJO permite repetir un caso con memoria limpia (p. ej. SUFIJO=r → ...-01r).
    chat_id = f"test-claude-{FECHA}-{caso}{os.environ.get('SUFIJO', '')}"
    turnos = []
    for i, msg in enumerate(mensajes):
        if i:
            time.sleep(PAUSA)
        t = llamar(chat_id, msg)
        t["chequeos"] = chequear(t)
        turnos.append(t)
        r = t.get("respuesta") or {}
        fallas = [k for k, v in t["chequeos"].items() if not v["ok"]]
        print(
            f"[{caso}.{i + 1}] {t['http_status']} {t['segundos']}s "
            f"ctx={r.get('context')} cont={r.get('continue')} "
            f"len={len(r.get('text') or '')} fallas={fallas}",
            flush=True,
        )
    out = {"caso": caso, "chatId": chat_id, "turnos": turnos}
    (SALIDA / f"caso-{caso}{os.environ.get('SUFIJO', '')}.json").write_text(json.dumps(out, ensure_ascii=False, indent=2))
    return out


def rechequear():
    """Recalcula los chequeos sobre las respuestas ya guardadas, sin llamar al bot."""
    for f in sorted(SALIDA.glob("caso-*.json")):
        out = json.loads(f.read_text())
        for t in out["turnos"]:
            t["chequeos"] = chequear(t)
        f.write_text(json.dumps(out, ensure_ascii=False, indent=2))


def main():
    SALIDA.mkdir(exist_ok=True)
    if sys.argv[1:] == ["--rechequear"]:
        return rechequear()
    elegidos = sys.argv[1:] or list(CASOS)
    resumen = []
    for j, caso in enumerate(elegidos):
        if j:
            time.sleep(PAUSA)
        out = correr(caso, CASOS[caso])
        for i, t in enumerate(out["turnos"]):
            r = t.get("respuesta") or {}
            resumen.append({
                "caso": caso,
                "turno": i + 1,
                "msg": t["msg"],
                "inicio_utc": t["inicio_utc"],
                "context": r.get("context"),
                "continue": r.get("continue"),
                "largo": len(r.get("text") or ""),
                "fallas": [k for k, v in t["chequeos"].items() if not v["ok"]],
            })
    (SALIDA / f"resumen{os.environ.get('SUFIJO', '')}.json").write_text(json.dumps(resumen, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
