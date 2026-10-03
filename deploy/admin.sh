#!/usr/bin/env bash
# Crea o actualiza un usuario del panel en producción (pide la clave por consola).
#   sudo bash /opt/evento-coopetrol/deploy/admin.sh usuario "Nombre Apellido"
set -euo pipefail
[ $# -ge 1 ] || { echo 'Uso: sudo bash deploy/admin.sh usuario "Nombre Apellido"'; exit 1; }
cd /opt/evento-coopetrol
exec sudo -u evento bash -c 'set -a; . /etc/evento-coopetrol/evento.env; set +a; exec node --disable-warning=ExperimentalWarning scripts/crear_admin.js "$@"' _ "$@"
