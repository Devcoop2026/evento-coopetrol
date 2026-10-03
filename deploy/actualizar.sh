#!/usr/bin/env bash
# Actualiza el código en producción sin tocar datos ni configuración. Ejecutar como root desde la carpeta con la versión nueva:
#   sudo bash deploy/actualizar.sh
set -euo pipefail

APP=/opt/evento-coopetrol
ORIGEN="$(cd "$(dirname "$0")/.." && pwd)"
[ "$(id -u)" -eq 0 ] || { echo "Ejecute como root (sudo)."; exit 1; }

echo "==> Respaldo previo"
systemctl start evento-coopetrol-respaldo.service

echo "==> Copiando código (se conservan data/ de producción, soportes y configuración)"
rsync -a --delete \
  --exclude '.git/' --exclude 'docs/' --exclude 'test/' --exclude 'respaldos/' --exclude 'node_modules/' \
  --exclude 'data/' \
  "$ORIGEN/" "$APP/"
# Tarifas, formulario del soporte y texto de habeas data vienen con el código (versionados y revisados).
# La versión anterior del servidor se conserva como *.anterior. config.json se mantiene propio de cada servidor.
for f in tarifas.json agencias_evento.json formulario_soporte.json habeas_data.json; do
  [ -f "$APP/data/$f" ] && ! cmp -s "$ORIGEN/data/$f" "$APP/data/$f" && cp -p "$APP/data/$f" "$APP/data/$f.anterior"
  install -o evento -g evento -m 640 "$ORIGEN/data/$f" "$APP/data/$f"
done
chown -R root:root "$APP"
chmod 755 "$APP"
chown -R evento:evento "$APP/data"

for u in evento-coopetrol.service evento-coopetrol-respaldo.service evento-coopetrol-respaldo.timer; do
  install -m 644 "$ORIGEN/deploy/$u" /etc/systemd/system/
done
systemctl daemon-reload
systemctl restart evento-coopetrol.service

sleep 2
PUERTO=$(grep -E '^PORT=' /etc/evento-coopetrol/evento.env | cut -d= -f2)
curl -fsS "http://127.0.0.1:${PUERTO:-3000}/api/cupos" >/dev/null \
  && echo "==> Actualización lista." \
  || { echo "==> El servicio no responde. Revise: journalctl -u evento-coopetrol -n 50"; exit 1; }
