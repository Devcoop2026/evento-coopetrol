"""Genera data/tarifas.json a partir de la primera hoja de docs/Evento.xlsx.

Uso:  py scripts/importar_tarifas.py [ruta_excel]
Luego reinicie el servidor para que las tarifas se recarguen en la base de datos.
"""
import json
import sys
from pathlib import Path

import openpyxl

RAIZ = Path(__file__).resolve().parent.parent
excel = Path(sys.argv[1]) if len(sys.argv) > 1 else RAIZ / "docs" / "Evento.xlsx"

hoja = openpyxl.load_workbook(excel, data_only=True).worksheets[0]

agencias = []
for fila in hoja.iter_rows(min_row=6, min_col=2, max_col=5, values_only=True):
    nombre, cupos, valor_invitado, valor_asociado = fila
    if not nombre or valor_invitado is None:
        continue
    agencias.append({
        "agencia": str(nombre).strip(),
        "cupos": int(cupos or 0),
        "valor_invitado": int(valor_invitado),
        "valor_asociado": int(valor_asociado),
    })

salida = {
    "evento": str(hoja["B4"].value or "").strip(),
    "inscripciones": str(hoja["B3"].value or "").strip(),
    "cuenta_contable": str(hoja["C2"].value or ""),
    "concepto": str(hoja["D2"].value or "").strip(),
    "agencias": agencias,
}

destino = RAIZ / "data" / "tarifas.json"
destino.write_text(json.dumps(salida, ensure_ascii=False, indent=2), encoding="utf-8")
print(f"{len(agencias)} agencias exportadas a {destino}")
