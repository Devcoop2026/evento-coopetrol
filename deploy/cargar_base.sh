#!/usr/bin/env bash
# Carga una base en producción desde la consola del servidor (alternativa al panel).
#   sudo bash /opt/evento-coopetrol/deploy/cargar_base.sh asociados /tmp/asociados.xlsx             (vista previa)
#   sudo bash /opt/evento-coopetrol/deploy/cargar_base.sh asociados /tmp/asociados.xlsx --confirmar (reemplaza)
# Después de cargar, borre el archivo del servidor: shred -u /tmp/asociados.xlsx
set -euo pipefail
[ $# -ge 2 ] || { echo 'Uso: sudo bash deploy/cargar_base.sh <asociados|coopetrolitos> <archivo> [--confirmar]'; exit 1; }
ARCHIVO="$(readlink -f "$2")"
[ -f "$ARCHIVO" ] || { echo "No se encontró el archivo: $2"; exit 1; }
cd /opt/evento-coopetrol
# El usuario del servicio lee el archivo por la entrada estándar (no necesita permisos sobre la ruta original).
TMP=$(sudo -u evento mktemp)
trap 'sudo -u evento rm -f "$TMP"' EXIT
sudo -u evento tee "$TMP" < "$ARCHIVO" > /dev/null
sudo -u evento bash -c 'set -a; . /etc/evento-coopetrol/evento.env; set +a; exec node --disable-warning=ExperimentalWarning scripts/cargar_base.js "$@"' _ "$1" "$TMP" "${3:-}"
