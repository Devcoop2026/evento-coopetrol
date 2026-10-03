#!/usr/bin/env bash
# Limpia registros en el servidor (hace un respaldo antes). Ejemplos:
#   sudo bash /opt/evento-coopetrol/deploy/limpiar.sh inscripciones              (vista previa)
#   sudo bash /opt/evento-coopetrol/deploy/limpiar.sh inscripciones --confirmar  (borra)
# Opciones: inscripciones | bases | auditoria | cupos | todo
set -euo pipefail
[ $# -ge 1 ] || { echo 'Uso: sudo bash deploy/limpiar.sh <inscripciones|bases|auditoria|cupos|todo> [--confirmar]'; exit 1; }
cd /opt/evento-coopetrol
if [ "${2:-}" = "--confirmar" ]; then systemctl stop evento-coopetrol; trap 'systemctl start evento-coopetrol' EXIT; fi
sudo -u evento bash -c 'set -a; . /etc/evento-coopetrol/evento.env; set +a; exec node --disable-warning=ExperimentalWarning scripts/limpiar.js "$@"' _ "$1" "${2:-}"
