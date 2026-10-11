#!/usr/bin/env python3
from __future__ import annotations

import argparse
import ast
import datetime as dt
import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

I18N_TOOLING_VERSION = "1.1.0"
LOCALES = ("nl_NL", "de_DE", "fr_FR", "es_ES", "it_IT", "pt_PT")

SCRIPT_DIR = Path(__file__).resolve().parent
ROOT = SCRIPT_DIR.parents[1]
DEFAULT_CONFIG_PATH = SCRIPT_DIR / "config.json"

SHARED_TRANSLATIONS = {
    "PHP %1$s or newer is required. This server runs PHP %2$s.": {
        "nl_NL": "PHP %1$s of nieuwer is vereist. Deze server draait PHP %2$s.",
        "de_DE": "PHP %1$s oder neuer ist erforderlich. Auf diesem Server läuft PHP %2$s.",
        "fr_FR": "PHP %1$s ou une version ultérieure est requis. Ce serveur utilise PHP %2$s.",
        "es_ES": "Se requiere PHP %1$s o posterior. Este servidor ejecuta PHP %2$s.",
        "it_IT": "È richiesto PHP %1$s o una versione successiva. Questo server utilizza PHP %2$s.",
        "pt_PT": "É necessário PHP %1$s ou mais recente. Este servidor utiliza PHP %2$s.",
    },
    "Core Blueprint must be installed and active.": {
        "nl_NL": "Core Blueprint moet geïnstalleerd en actief zijn.",
        "de_DE": "Core Blueprint muss installiert und aktiviert sein.",
        "fr_FR": "Core Blueprint doit être installé et activé.",
        "es_ES": "Core Blueprint debe estar instalado y activo.",
        "it_IT": "Core Blueprint deve essere installato e attivo.",
        "pt_PT": "O Core Blueprint tem de estar instalado e ativo.",
    },
    "Core API %1$s or a newer compatible minor version is required. Available Core API: %2$s.": {
        "nl_NL": "Core API %1$s of een nieuwere compatibele minorversie is vereist. Beschikbare Core API: %2$s.",
        "de_DE": "Core API %1$s oder eine neuere kompatible Minor-Version ist erforderlich. Verfügbare Core API: %2$s.",
        "fr_FR": "Core API %1$s ou une version mineure compatible plus récente est requise. Core API disponible : %2$s.",
        "es_ES": "Se requiere Core API %1$s o una versión menor compatible más reciente. Core API disponible: %2$s.",
        "it_IT": "È richiesta Core API %1$s o una versione minor compatibile più recente. Core API disponibile: %2$s.",
        "pt_PT": "É necessária a Core API %1$s ou uma versão minor compatível mais recente. Core API disponível: %2$s.",
    },
    "Required Core Blueprint Base contracts are unavailable.": {
        "nl_NL": "Vereiste Core Blueprint Base-contracten zijn niet beschikbaar.",
        "de_DE": "Erforderliche Core Blueprint Base-Verträge sind nicht verfügbar.",
        "fr_FR": "Les contrats Core Blueprint Base requis ne sont pas disponibles.",
        "es_ES": "Los contratos requeridos de Core Blueprint Base no están disponibles.",
        "it_IT": "I contratti Core Blueprint Base richiesti non sono disponibili.",
        "pt_PT": "Os contratos Core Blueprint Base necessários não estão disponíveis.",
    },
}


# Reviewed CRM Sales W2A interface strings; generated PO/MO must remain canonical.
SHARED_TRANSLATIONS.update({
    "You do not have permission to view CRM Sales.": {
        "nl_NL": "U hebt geen toestemming om CRM Sales te bekijken.",
        "de_DE": "Sie sind nicht berechtigt, CRM Sales anzuzeigen.",
        "fr_FR": "Vous n’avez pas l’autorisation de consulter CRM Sales.",
        "es_ES": "No tiene permiso para ver CRM Sales.",
        "it_IT": "Non hai il permesso di visualizzare CRM Sales.",
        "pt_PT": "Não tem permissão para consultar o CRM Sales."
    },
    "Internal staff records. Read-only overview.": {
        "nl_NL": "Interne medewerkersgegevens. Alleen-lezenoverzicht.",
        "de_DE": "Interne Mitarbeiterdatensätze. Schreibgeschützte Übersicht.",
        "fr_FR": "Dossiers internes du personnel. Vue en lecture seule.",
        "es_ES": "Registros internos del personal. Vista de solo lectura.",
        "it_IT": "Record interni del personale. Panoramica di sola lettura.",
        "pt_PT": "Registos internos da equipa. Vista só de leitura."
    },
    "All statuses": {
        "nl_NL": "Alle statussen",
        "de_DE": "Alle Status",
        "fr_FR": "Tous les statuts",
        "es_ES": "Todos los estados",
        "it_IT": "Tutti gli stati",
        "pt_PT": "Todos os estados"
    },
    "Owner": {
        "nl_NL": "Eigenaar",
        "de_DE": "Verantwortliche Person",
        "fr_FR": "Responsable",
        "es_ES": "Responsable",
        "it_IT": "Responsabile",
        "pt_PT": "Responsável"
    },
    "All records": {
        "nl_NL": "Alle records",
        "de_DE": "Alle Datensätze",
        "fr_FR": "Tous les dossiers",
        "es_ES": "Todos los registros",
        "it_IT": "Tutti i record",
        "pt_PT": "Todos os registos"
    },
    "Assigned to me": {
        "nl_NL": "Aan mij toegewezen",
        "de_DE": "Mir zugewiesen",
        "fr_FR": "Qui me sont attribués",
        "es_ES": "Asignados a mí",
        "it_IT": "Assegnati a me",
        "pt_PT": "Atribuídos a mim"
    },
    "Reset filters": {
        "nl_NL": "Filters wissen",
        "de_DE": "Filter zurücksetzen",
        "fr_FR": "Réinitialiser les filtres",
        "es_ES": "Restablecer filtros",
        "it_IT": "Reimposta i filtri",
        "pt_PT": "Repor filtros"
    },
    "Next action": {
        "nl_NL": "Volgende actie",
        "de_DE": "Nächste Aktion",
        "fr_FR": "Prochaine action",
        "es_ES": "Próxima acción",
        "it_IT": "Prossima azione",
        "pt_PT": "Próxima ação"
    },
    "Updated": {
        "nl_NL": "Bijgewerkt",
        "de_DE": "Aktualisiert",
        "fr_FR": "Mis à jour",
        "es_ES": "Actualizado",
        "it_IT": "Aggiornato",
        "pt_PT": "Atualizado"
    },
    "No matching records.": {
        "nl_NL": "Geen overeenkomende records.",
        "de_DE": "Keine passenden Datensätze.",
        "fr_FR": "Aucun dossier correspondant.",
        "es_ES": "No hay registros coincidentes.",
        "it_IT": "Nessun record corrispondente.",
        "pt_PT": "Não existem registos correspondentes."
    },
    "Sales pagination": {
        "nl_NL": "Sales-paginering",
        "de_DE": "Sales-Seitennavigation",
        "fr_FR": "Pagination des ventes",
        "es_ES": "Paginación de ventas",
        "it_IT": "Paginazione vendite",
        "pt_PT": "Paginação de vendas"
    },
    "First page": {
        "nl_NL": "Eerste pagina",
        "de_DE": "Erste Seite",
        "fr_FR": "Première page",
        "es_ES": "Primera página",
        "it_IT": "Prima pagina",
        "pt_PT": "Primeira página"
    },
    "Next page": {
        "nl_NL": "Volgende pagina",
        "de_DE": "Nächste Seite",
        "fr_FR": "Page suivante",
        "es_ES": "Página siguiente",
        "it_IT": "Pagina successiva",
        "pt_PT": "Página seguinte"
    },
    "Back to overview": {
        "nl_NL": "Terug naar overzicht",
        "de_DE": "Zurück zur Übersicht",
        "fr_FR": "Retour à la vue d’ensemble",
        "es_ES": "Volver al resumen",
        "it_IT": "Torna alla panoramica",
        "pt_PT": "Voltar à visão geral"
    },
    "Record not found.": {
        "nl_NL": "Record niet gevonden.",
        "de_DE": "Datensatz nicht gefunden.",
        "fr_FR": "Dossier introuvable.",
        "es_ES": "Registro no encontrado.",
        "it_IT": "Record non trovato.",
        "pt_PT": "Registo não encontrado."
    },
    "Read-only detail. Changes are not available on this screen.": {
        "nl_NL": "Alleen-lezenweergave. Wijzigingen zijn niet mogelijk op dit scherm.",
        "de_DE": "Schreibgeschützte Detailansicht. Änderungen sind auf diesem Bildschirm nicht möglich.",
        "fr_FR": "Détail en lecture seule. Les modifications ne sont pas disponibles sur cet écran.",
        "es_ES": "Detalle de solo lectura. No es posible realizar cambios en esta pantalla.",
        "it_IT": "Dettaglio di sola lettura. Le modifiche non sono disponibili in questa schermata.",
        "pt_PT": "Detalhe só de leitura. Não é possível fazer alterações neste ecrã."
    },
    "Pipeline ID": {
        "nl_NL": "Pipeline-ID",
        "de_DE": "Pipeline-ID",
        "fr_FR": "ID du pipeline",
        "es_ES": "ID del pipeline",
        "it_IT": "ID della pipeline",
        "pt_PT": "ID do pipeline"
    },
    "Stage ID": {
        "nl_NL": "Fase-ID",
        "de_DE": "Phasen-ID",
        "fr_FR": "ID de l’étape",
        "es_ES": "ID de la etapa",
        "it_IT": "ID della fase",
        "pt_PT": "ID da fase"
    },
    "Revision": {
        "nl_NL": "Revisie",
        "de_DE": "Revision",
        "fr_FR": "Révision",
        "es_ES": "Revisión",
        "it_IT": "Revisione",
        "pt_PT": "Revisão"
    },
    "Created": {
        "nl_NL": "Aangemaakt",
        "de_DE": "Erstellt",
        "fr_FR": "Créé",
        "es_ES": "Creado",
        "it_IT": "Creato",
        "pt_PT": "Criado"
    },
    "Source": {
        "nl_NL": "Bron",
        "de_DE": "Quelle",
        "fr_FR": "Source",
        "es_ES": "Origen",
        "it_IT": "Fonte",
        "pt_PT": "Origem"
    },
    "Converted Opportunity ID": {
        "nl_NL": "ID geconverteerde Opportunity",
        "de_DE": "ID der konvertierten Verkaufschance",
        "fr_FR": "ID de l’opportunité convertie",
        "es_ES": "ID de oportunidad convertida",
        "it_IT": "ID opportunità convertita",
        "pt_PT": "ID da oportunidade convertida"
    },
    "Amount (minor units)": {
        "nl_NL": "Bedrag (kleinste munteenheid)",
        "de_DE": "Betrag (kleinste Währungseinheit)",
        "fr_FR": "Montant (unité monétaire minimale)",
        "es_ES": "Importe (unidad monetaria mínima)",
        "it_IT": "Importo (unità monetaria minima)",
        "pt_PT": "Montante (unidade monetária mínima)"
    },
    "Currency": {
        "nl_NL": "Valuta",
        "de_DE": "Währung",
        "fr_FR": "Devise",
        "es_ES": "Moneda",
        "it_IT": "Valuta",
        "pt_PT": "Moeda"
    },
    "Source Lead ID": {
        "nl_NL": "Bron-Lead-ID",
        "de_DE": "Ursprungs-Lead-ID",
        "fr_FR": "ID du lead source",
        "es_ES": "ID del lead de origen",
        "it_IT": "ID lead di origine",
        "pt_PT": "ID do lead de origem"
    },
    "Participants": {
        "nl_NL": "Betrokkenen",
        "de_DE": "Beteiligte",
        "fr_FR": "Participants",
        "es_ES": "Participantes",
        "it_IT": "Partecipanti",
        "pt_PT": "Participantes"
    },
    "No participants.": {
        "nl_NL": "Geen betrokkenen.",
        "de_DE": "Keine Beteiligten.",
        "fr_FR": "Aucun participant.",
        "es_ES": "Sin participantes.",
        "it_IT": "Nessun partecipante.",
        "pt_PT": "Sem participantes."
    },
    "Activity history": {
        "nl_NL": "Activiteitengeschiedenis",
        "de_DE": "Aktivitätsverlauf",
        "fr_FR": "Historique des activités",
        "es_ES": "Historial de actividad",
        "it_IT": "Cronologia delle attività",
        "pt_PT": "Histórico de atividades"
    },
    "Event": {
        "nl_NL": "Gebeurtenis",
        "de_DE": "Ereignis",
        "fr_FR": "Événement",
        "es_ES": "Evento",
        "it_IT": "Evento",
        "pt_PT": "Evento"
    },
    "Actor ID": {
        "nl_NL": "Actor-ID",
        "de_DE": "Akteur-ID",
        "fr_FR": "ID de l’auteur",
        "es_ES": "ID del actor",
        "it_IT": "ID dell’attore",
        "pt_PT": "ID do interveniente"
    },
    "No history entries.": {
        "nl_NL": "Geen geschiedenisitems.",
        "de_DE": "Keine Verlaufseinträge.",
        "fr_FR": "Aucune entrée dans l’historique.",
        "es_ES": "No hay entradas en el historial.",
        "it_IT": "Nessuna voce nella cronologia.",
        "pt_PT": "Sem entradas no histórico."
    },
    "Unable to display CRM Sales records. Check the filters or your access.": {
        "nl_NL": "Kan CRM Sales-records niet tonen. Controleer de filters of uw toegangsrechten.",
        "de_DE": "CRM-Sales-Datensätze können nicht angezeigt werden. Prüfen Sie die Filter oder Ihre Zugriffsrechte.",
        "fr_FR": "Impossible d’afficher les dossiers CRM Sales. Vérifiez les filtres ou vos droits d’accès.",
        "es_ES": "No se pueden mostrar los registros de CRM Sales. Compruebe los filtros o sus permisos.",
        "it_IT": "Impossibile mostrare i record di CRM Sales. Controlla i filtri o i permessi.",
        "pt_PT": "Não é possível apresentar os registos do CRM Sales. Verifique os filtros ou as permissões."
    },
    "Open": {
        "nl_NL": "Open",
        "de_DE": "Offen",
        "fr_FR": "Ouvert",
        "es_ES": "Abierto",
        "it_IT": "Aperto",
        "pt_PT": "Aberto"
    },
    "Qualified": {
        "nl_NL": "Gekwalificeerd",
        "de_DE": "Qualifiziert",
        "fr_FR": "Qualifié",
        "es_ES": "Cualificado",
        "it_IT": "Qualificato",
        "pt_PT": "Qualificado"
    },
    "Disqualified": {
        "nl_NL": "Afgewezen",
        "de_DE": "Disqualifiziert",
        "fr_FR": "Disqualifié",
        "es_ES": "Descalificado",
        "it_IT": "Squalificato",
        "pt_PT": "Desqualificado"
    },
    "Converted": {
        "nl_NL": "Geconverteerd",
        "de_DE": "Konvertiert",
        "fr_FR": "Converti",
        "es_ES": "Convertido",
        "it_IT": "Convertito",
        "pt_PT": "Convertido"
    },
    "Won": {
        "nl_NL": "Gewonnen",
        "de_DE": "Gewonnen",
        "fr_FR": "Gagné",
        "es_ES": "Ganado",
        "it_IT": "Vinto",
        "pt_PT": "Ganho"
    },
    "Lost": {
        "nl_NL": "Verloren",
        "de_DE": "Verloren",
        "fr_FR": "Perdu",
        "es_ES": "Perdido",
        "it_IT": "Perso",
        "pt_PT": "Perdido"
    },
    "Leads": {
        "nl_NL": "Leads",
        "de_DE": "Leads",
        "fr_FR": "Leads",
        "es_ES": "Leads",
        "it_IT": "Lead",
        "pt_PT": "Leads"
    },
    "Opportunities": {
        "nl_NL": "Opportunities",
        "de_DE": "Verkaufschancen",
        "fr_FR": "Opportunités",
        "es_ES": "Oportunidades",
        "it_IT": "Opportunità",
        "pt_PT": "Oportunidades"
    }
})


SHARED_TRANSLATIONS.update({
    "Filter": {"nl_NL": "Filteren", "de_DE": "Filtern", "fr_FR": "Filtrer", "es_ES": "Filtrar", "it_IT": "Filtra", "pt_PT": "Filtrar"},
    "Title": {"nl_NL": "Titel", "de_DE": "Titel", "fr_FR": "Titre", "es_ES": "Título", "it_IT": "Titolo", "pt_PT": "Título"},
})


def fail(message: str) -> None:
    raise SystemExit(f"ERROR: {message}")


def load_config(path: Path) -> dict:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        fail(f"could not read i18n config {path}: {exc}")

    required = {
        "product",
        "domain",
        "main_file",
        "version_constant",
        "exclude",
        "skip_js",
        "commit_mo",
        "source_paths",
    }
    missing = sorted(required - set(data))
    if missing:
        fail("missing i18n config keys: " + ", ".join(missing))

    data.setdefault("commit_l10n_php", False)
    return data


def command(name: str) -> str:
    resolved = shutil.which(name)
    if not resolved:
        fail(f"required i18n command is unavailable: {name}")
    return resolved


def run(args: list[str], *, capture: bool = False) -> str:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=capture)
    if result.returncode:
        detail = (result.stderr or result.stdout).strip() if capture else ""
        suffix = f": {detail}" if detail else ""
        fail(f"command failed ({result.returncode}): {' '.join(args)}{suffix}")
    return result.stdout if capture else ""


def source_version(cfg: dict) -> str:
    source = (ROOT / cfg["main_file"]).read_text(encoding="utf-8")
    header = re.search(r"^\s*\*\s*Version:\s*([^\s]+)", source, re.M)
    constant = re.search(
        r"define\(\s*['\"]"
        + re.escape(cfg["version_constant"])
        + r"['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)",
        source,
    )
    if not header or not constant or header.group(1) != constant.group(1):
        fail("plugin Version header and version constant must exist and match")
    return header.group(1)


def source_stamp(cfg: dict, git_bin: str) -> str:
    paths = [str(path) for path in cfg["source_paths"]]
    raw = run([git_bin, "log", "-1", "--format=%ct", "--", *paths], capture=True).strip()
    if not raw.isdigit():
        fail("could not resolve deterministic source timestamp from git")
    stamp = dt.datetime.fromtimestamp(int(raw), dt.timezone.utc)
    return stamp.strftime("%Y-%m-%d %H:%M+0000")


def generate_pot(cfg: dict, output: Path, wp_bin: str, version: str) -> None:
    args = [
        wp_bin,
        "i18n",
        "make-pot",
        ".",
        str(output),
        f"--domain={cfg['domain']}",
        "--exclude=" + ",".join(cfg["exclude"]),
        "--headers="
        + json.dumps(
            {"Project-Id-Version": f"{cfg['product']} {version}"},
            separators=(",", ":"),
        ),
        "--quiet",
    ]
    if cfg["skip_js"]:
        args.append("--skip-js")
    run(args)


def rewrite_header(path: Path, values: dict[str, str], *, insert_missing: bool = False) -> None:
    lines = path.read_text(encoding="utf-8").splitlines()
    found: set[str] = set()

    for index, line in enumerate(lines):
        for key, value in values.items():
            if line.startswith(f'"{key}:'):
                lines[index] = f'"{key}: {value}\\n"'
                found.add(key)

    missing = [key for key in values if key not in found]
    if missing and insert_missing:
        header_end = next(
            (i for i, line in enumerate(lines[1:], start=1) if line == ""),
            None,
        )
        if header_end is None:
            fail(f"could not locate PO metadata header in {path.relative_to(ROOT)}")
        for key in reversed(missing):
            lines.insert(header_end, f'"{key}: {values[key]}\\n"')
        missing = []

    if missing:
        fail(f"catalog header missing {missing} in {path.relative_to(ROOT)}")

    path.write_text("\n".join(lines).rstrip() + "\n", encoding="utf-8")


def header_value(path: Path, key: str) -> str | None:
    prefix = f'"{key}: '
    for line in path.read_text(encoding="utf-8").splitlines():
        if line.startswith(prefix) and line.endswith('\\n"'):
            return line[len(prefix) : -3]
    return None


def po_literal(fragment: str) -> str:
    try:
        value = ast.literal_eval(fragment.strip())
    except (SyntaxError, ValueError) as exc:
        fail(f"invalid PO string literal: {fragment}: {exc}")
    if not isinstance(value, str):
        fail(f"invalid PO string value: {fragment}")
    return value


def directive(lines: list[str], keyword: str) -> str | None:
    prefix = keyword + " "
    for index, line in enumerate(lines):
        if not line.startswith(prefix):
            continue
        value = po_literal(line[len(prefix) :])
        cursor = index + 1
        while cursor < len(lines) and lines[cursor].startswith('"'):
            value += po_literal(lines[cursor])
            cursor += 1
        return value
    return None


def plural_values(lines: list[str]) -> list[str]:
    values: dict[int, str] = {}
    index = 0
    while index < len(lines):
        match = re.match(r'^msgstr\[(\d+)\]\s+(".*")$', lines[index])
        if not match:
            index += 1
            continue
        plural_index = int(match.group(1))
        value = po_literal(match.group(2))
        index += 1
        while index < len(lines) and lines[index].startswith('"'):
            value += po_literal(lines[index])
            index += 1
        values[plural_index] = value
    return [values[key] for key in sorted(values)]


def blocks(path: Path) -> list[str]:
    return re.split(r"\n\s*\n", path.read_text(encoding="utf-8").strip())


def entry_key(block: str) -> str | None:
    lines = block.splitlines()
    msgid = directive(lines, "msgid")
    if msgid is None or msgid == "":
        return None
    context = directive(lines, "msgctxt")
    plural = directive(lines, "msgid_plural")
    key = msgid if plural is None else msgid + "\x00" + plural
    return key if context is None else context + "\x04" + key


def key_set(path: Path) -> set[str]:
    return {key for block in blocks(path) if (key := entry_key(block)) is not None}


def has_fuzzy(block: str) -> bool:
    for line in block.splitlines():
        if not line.startswith("#,"):
            continue
        flags = {flag.strip() for flag in line[2:].split(",")}
        if "fuzzy" in flags:
            return True
    return False


def translation_values(block: str) -> list[str]:
    lines = block.splitlines()
    if directive(lines, "msgid_plural") is not None:
        return plural_values(lines)
    value = directive(lines, "msgstr")
    return [] if value is None else [value]


def replace_singular_translation(block: str, translation: str) -> str:
    lines = block.splitlines()
    if directive(lines, "msgid_plural") is not None:
        fail("shared translation target unexpectedly became plural")

    cleaned: list[str] = []
    for line in lines:
        if line.startswith("#,"):
            flags = [
                flag.strip()
                for flag in line[2:].split(",")
                if flag.strip() and flag.strip() != "fuzzy"
            ]
            if flags:
                cleaned.append("#, " + ", ".join(flags))
        else:
            cleaned.append(line)

    for index, line in enumerate(cleaned):
        if not line.startswith("msgstr "):
            continue
        end = index + 1
        while end < len(cleaned) and cleaned[end].startswith('"'):
            end += 1
        replacement = "msgstr " + json.dumps(translation, ensure_ascii=False)
        return "\n".join(cleaned[:index] + [replacement] + cleaned[end:])

    fail("shared translation target has no msgstr")


def apply_shared_translations(po_path: Path, locale: str, pot_keys: set[str]) -> None:
    current = blocks(po_path)
    by_msgid: dict[str, int] = {}

    for index, block in enumerate(current):
        lines = block.splitlines()
        msgid = directive(lines, "msgid")
        if not msgid:
            continue
        if directive(lines, "msgctxt") is None and directive(lines, "msgid_plural") is None:
            by_msgid[msgid] = index

    for msgid, translations in SHARED_TRANSLATIONS.items():
        if msgid not in pot_keys:
            continue
        if msgid not in by_msgid:
            fail(f"{locale} PO missing shared translation target: {msgid}")
        current[by_msgid[msgid]] = replace_singular_translation(
            current[by_msgid[msgid]], translations[locale]
        )

    po_path.write_text("\n\n".join(current).rstrip() + "\n", encoding="utf-8")


def validate_po(
    po_path: Path,
    pot_keys: set[str],
    msgfmt_bin: str,
    mo_output: Path | None = None,
) -> None:
    po_blocks = blocks(po_path)
    po_keys = {key for block in po_blocks if (key := entry_key(block)) is not None}
    missing = sorted(pot_keys - po_keys)
    stale = sorted(po_keys - pot_keys)

    if missing:
        fail(f"{po_path.name} missing {len(missing)} current POT entries; first: {missing[0]}")
    if stale:
        fail(f"{po_path.name} contains {len(stale)} stale non-obsolete entries; first: {stale[0]}")
    if re.search(r"^#~", po_path.read_text(encoding="utf-8"), re.M):
        fail(f"obsolete entries remain in {po_path.name}")

    for block in po_blocks:
        key = entry_key(block)
        if key is None:
            continue
        if has_fuzzy(block):
            fail(f"fuzzy translation in {po_path.name}: {key}")
        values = translation_values(block)
        if not values or any(not value.strip() for value in values):
            fail(f"untranslated entry in {po_path.name}: {key}")

    if mo_output is None:
        with tempfile.NamedTemporaryFile(prefix="cb-i18n-", suffix=".mo") as handle:
            run(
                [
                    msgfmt_bin,
                    "--check-format",
                    "--check-header",
                    "-o",
                    handle.name,
                    str(po_path),
                ]
            )
        return

    run(
        [
            msgfmt_bin,
            "--check-format",
            "--check-header",
            "-o",
            str(mo_output),
            str(po_path),
        ]
    )


def normalize_po_headers(
    po: Path, cfg: dict, version: str, stamp: str, locale: str
) -> None:
    rewrite_header(
        po,
        {
            "Project-Id-Version": f"{cfg['product']} {version}",
            "POT-Creation-Date": stamp,
            "PO-Revision-Date": stamp,
            "Last-Translator": "Core Blueprint",
            "Language-Team": locale,
            "Language": locale,
            "Generated-By": f"Core Blueprint i18n tooling {I18N_TOOLING_VERSION}",
        },
        insert_missing=True,
    )


def generate_l10n_php(wp_bin: str, po: Path, output_dir: Path, expected_name: str) -> Path:
    output_dir.mkdir(parents=True, exist_ok=True)
    run([wp_bin, "i18n", "make-php", str(po), str(output_dir), "--quiet"])
    candidates = list(output_dir.glob("*.l10n.php"))
    if len(candidates) != 1:
        fail(f"wp i18n make-php produced {len(candidates)} PHP catalogs for {po.name}; expected 1")
    generated = candidates[0]
    if generated.name != expected_name:
        fail(
            f"wp i18n make-php produced unexpected filename {generated.name}; expected {expected_name}"
        )
    return generated


def do_update(args: argparse.Namespace, cfg: dict) -> None:
    wp = command(args.wp_bin)
    msgmerge = command(args.msgmerge_bin)
    msgattrib = command(args.msgattrib_bin)
    msgfmt = command(args.msgfmt_bin)
    git_bin = command(args.git_bin)

    version = source_version(cfg)
    stamp = source_stamp(cfg, git_bin)
    lang = ROOT / "languages"
    lang.mkdir(parents=True, exist_ok=True)
    pot = lang / f"{cfg['domain']}.pot"

    generate_pot(cfg, pot, wp, version)
    rewrite_header(
        pot,
        {
            "Project-Id-Version": f"{cfg['product']} {version}",
            "POT-Creation-Date": stamp,
        },
    )
    pot_keys = key_set(pot)
    if not pot_keys:
        fail("generated POT contains no translatable messages")

    for locale in LOCALES:
        po = lang / f"{cfg['domain']}-{locale}.po"
        if not po.is_file():
            fail(f"missing reviewed translation source: {po.relative_to(ROOT)}")

        run(
            [
                msgmerge,
                "--update",
                "--backup=none",
                "--no-fuzzy-matching",
                str(po),
                str(pot),
            ]
        )
        tmp = po.with_suffix(".po.tmp")
        run([msgattrib, "--no-obsolete", "-o", str(tmp), str(po)])
        tmp.replace(po)

        apply_shared_translations(po, locale, pot_keys)
        normalize_po_headers(po, cfg, version, stamp, locale)

        mo_output = lang / f"{cfg['domain']}-{locale}.mo" if cfg["commit_mo"] else None
        validate_po(po, pot_keys, msgfmt, mo_output)

        if cfg["commit_l10n_php"]:
            expected_name = f"{cfg['domain']}-{locale}.l10n.php"
            with tempfile.TemporaryDirectory(prefix="cb-i18n-php-") as tmpdir:
                generated = generate_l10n_php(wp, po, Path(tmpdir), expected_name)
                shutil.copyfile(generated, lang / expected_name)

    print(
        f"PASS: {cfg['product']} catalogs updated from source; "
        f"locales={len(LOCALES)} tooling={I18N_TOOLING_VERSION}"
    )


def do_check(args: argparse.Namespace, cfg: dict) -> None:
    wp = command(args.wp_bin)
    msgfmt = command(args.msgfmt_bin)
    version = source_version(cfg)
    lang = ROOT / "languages"
    pot = lang / f"{cfg['domain']}.pot"

    if not pot.is_file():
        fail(f"missing committed POT: {pot.relative_to(ROOT)}")

    committed_keys = key_set(pot)
    expected_project = f"{cfg['product']} {version}"
    if header_value(pot, "Project-Id-Version") != expected_project:
        fail(f"POT Project-Id-Version does not match current release: {expected_project}")

    with tempfile.TemporaryDirectory(prefix="cb-i18n-check-") as tmpdir:
        temp_root = Path(tmpdir)
        fresh = temp_root / pot.name
        generate_pot(cfg, fresh, wp, version)
        fresh_keys = key_set(fresh)
        if committed_keys != fresh_keys:
            fail(
                f"POT/source drift: missing={len(fresh_keys - committed_keys)} "
                f"stale={len(committed_keys - fresh_keys)}"
            )

        for locale in LOCALES:
            po = lang / f"{cfg['domain']}-{locale}.po"
            if not po.is_file():
                fail(f"missing locale PO: {po.relative_to(ROOT)}")
            if header_value(po, "Project-Id-Version") != expected_project:
                fail(f"{po.name} Project-Id-Version does not match current release: {expected_project}")
            if header_value(po, "Language") != locale:
                fail(f"{po.name} Language header must be {locale}")

            validate_po(po, committed_keys, msgfmt)

            by_msgid: dict[str, str] = {}
            for block in blocks(po):
                lines = block.splitlines()
                msgid = directive(lines, "msgid")
                if not msgid:
                    continue
                if directive(lines, "msgctxt") is None and directive(lines, "msgid_plural") is None:
                    values = translation_values(block)
                    by_msgid[msgid] = values[0] if values else ""

            for msgid in {key for key in SHARED_TRANSLATIONS if key in committed_keys}:
                if by_msgid.get(msgid) != SHARED_TRANSLATIONS[msgid][locale]:
                    fail(f"shared translation drift in {po.name}: {msgid}")

            if cfg["commit_mo"]:
                committed_mo = lang / f"{cfg['domain']}-{locale}.mo"
                if not committed_mo.is_file():
                    fail(f"missing committed MO: {committed_mo.relative_to(ROOT)}")
                generated_mo = temp_root / committed_mo.name
                validate_po(po, committed_keys, msgfmt, generated_mo)
                if generated_mo.read_bytes() != committed_mo.read_bytes():
                    fail(f"committed MO is not reproducible from {po.name}")

            if cfg["commit_l10n_php"]:
                committed_php = lang / f"{cfg['domain']}-{locale}.l10n.php"
                if not committed_php.is_file():
                    fail(f"missing committed PHP catalog: {committed_php.relative_to(ROOT)}")
                php_dir = temp_root / f"php-{locale}"
                generated_php = generate_l10n_php(wp, po, php_dir, committed_php.name)
                if generated_php.read_bytes() != committed_php.read_bytes():
                    fail(f"committed PHP catalog is not reproducible from {po.name}")

    print(
        f"PASS: {cfg['product']} i18n check source=POT=PO "
        f"locales={len(LOCALES)} tooling={I18N_TOOLING_VERSION}"
    )


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("update", "check"))
    parser.add_argument("--config", default=str(DEFAULT_CONFIG_PATH))
    parser.add_argument("--wp-bin", default="wp")
    parser.add_argument("--msgmerge-bin", default="msgmerge")
    parser.add_argument("--msgattrib-bin", default="msgattrib")
    parser.add_argument("--msgfmt-bin", default="msgfmt")
    parser.add_argument("--git-bin", default="git")
    args = parser.parse_args()

    cfg = load_config(Path(args.config))
    if args.mode == "update":
        do_update(args, cfg)
    else:
        do_check(args, cfg)


if __name__ == "__main__":
    main()
