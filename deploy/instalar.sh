#!/usr/bin/env bash
# Instalación inicial en Linux (Ubuntu/Debian/RHEL con systemd). Ejecutar como root desde la carpeta del proyecto:
#   sudo bash deploy/instalar.sh             producción (detrás de Nginx, solo 127.0.0.1:3000)
#   sudo bash deploy/instalar.sh --pruebas   ambiente de pruebas: red interna en el puerto 3100 y datos ficticios
# Es idempotente: se puede volver a ejecutar sin perder datos ni configuración.
set -euo pipefail
PRUEBAS=0
[ "${1:-}" = "--pruebas" ] && PRUEBAS=1

APP=/opt/evento-coopetrol
DATOS=/var/lib/evento-coopetrol
RESPALDOS=/var/backups/evento-coopetrol
CONF=/etc/evento-coopetrol
ORIGEN="$(cd "$(dirname "$0")/.." && pwd)"

[ "$(id -u)" -eq 0 ] || { echo "Ejecute como root (sudo)."; exit 1; }
command -v node >/dev/null || { echo "Instale Node.js 24 LTS (https://nodejs.org o NodeSource) y vuelva a ejecutar."; exit 1; }
NODE_MAYOR=$(node -p 'process.versions.node.split(".")[0]')
[ "$NODE_MAYOR" -ge 22 ] || { echo "Se requiere Node.js 22.13 o superior (hay $(node -v))."; exit 1; }
[ "$NODE_MAYOR" -ge 24 ] || echo "Aviso: se recomienda Node.js 24 LTS (hay $(node -v)); en Node 22 el módulo SQLite es experimental."
[ "$(command -v node)" = /usr/bin/node ] || echo "Aviso: node está en $(command -v node); ajuste ExecStart en los archivos .service."
command -v rsync >/dev/null || { echo "Instale rsync (apt install rsync)."; exit 1; }

echo "==> Usuario de servicio 'evento'"
id evento >/dev/null 2>&1 || useradd --system --home-dir "$APP" --shell /usr/sbin/nologin evento

echo "==> Carpetas"
install -d -o root -g root -m 755 "$APP"
install -d -o evento -g evento -m 700 "$DATOS" "$DATOS/soportes" "$RESPALDOS"
install -d -o root -g evento -m 750 "$CONF"

echo "==> Código en $APP"
# La primera vez se copian también los archivos de configuración (config, habeas data, formulario, datos de prueba).
rsync -a --delete \
  --exclude '.git/' --exclude 'docs/' --exclude 'test/' --exclude 'respaldos/' --exclude 'node_modules/' \
  --exclude 'data/evento.db*' --exclude 'data/soportes/' --exclude 'data/asociados.json' --exclude 'data/coopetrolitos.json' \
  --exclude 'data/*.seed.json' \
  --exclude 'data/config.json' --exclude 'data/habeas_data.json' --exclude 'data/formulario_soporte.json' \
  "$ORIGEN/" "$APP/"
for f in config.json habeas_data.json formulario_soporte.json; do
  [ -f "$APP/data/$f" ] || install -o evento -g evento -m 640 "$ORIGEN/data/$f" "$APP/data/$f"
done
# Los datos ficticios solo se instalan en el ambiente de pruebas.
if [ "$PRUEBAS" = 1 ]; then
  for f in asociados.seed.json coopetrolitos.seed.json; do install -o evento -g evento -m 640 "$ORIGEN/data/$f" "$APP/data/$f"; done
fi
chown -R root:root "$APP"
chmod 755 "$APP"
chown -R evento:evento "$APP/data"
chmod 750 "$APP/data"

echo "==> Configuración en $CONF/evento.env"
if [ ! -f "$CONF/evento.env" ]; then
  install -o root -g evento -m 640 "$ORIGEN/deploy/evento-coopetrol.env.example" "$CONF/evento.env"
  if [ "$PRUEBAS" = 1 ]; then
    # Pruebas: sin proxy, accesible en la red interna, con asociados ficticios (no cargar datos reales por HTTP).
    sed -i -e 's/^HOST=.*/HOST=0.0.0.0/' -e 's/^PORT=.*/PORT=3100/' -e 's/^TRUST_PROXY=.*/TRUST_PROXY=0/' "$CONF/evento.env"
    printf '\n# Ambiente de pruebas\nDATOS_PRUEBA=1\n' >> "$CONF/evento.env"
    echo "    Configurado como ambiente de pruebas (puerto 3100, datos ficticios)."
  fi
fi
# Clave de cifrado de los respaldos (AES-256-GCM).
if ! grep -qE '^RESPALDO_CLAVE=[0-9a-f]{64}$' "$CONF/evento.env"; then
  CLAVE_RESPALDO=$(node -e 'console.log(require("crypto").randomBytes(32).toString("hex"))')
  if grep -q '^RESPALDO_CLAVE=' "$CONF/evento.env"; then
    sed -i "s/^RESPALDO_CLAVE=.*/RESPALDO_CLAVE=$CLAVE_RESPALDO/" "$CONF/evento.env"
  else
    echo "RESPALDO_CLAVE=$CLAVE_RESPALDO" >> "$CONF/evento.env"
  fi
  echo "    Se generó RESPALDO_CLAVE (cifrado de respaldos). Guárdela en el gestor de secretos: sin ella no se puede restaurar."
fi
if ! grep -qE '^EVENTO_SECRETO=.{32,}' "$CONF/evento.env"; then
  SECRETO=$(node -e 'console.log(require("crypto").randomBytes(32).toString("hex"))')
  sed -i "s/^EVENTO_SECRETO=.*/EVENTO_SECRETO=$SECRETO/" "$CONF/evento.env"
  grep -q '^EVENTO_SECRETO=' "$CONF/evento.env" || echo "EVENTO_SECRETO=$SECRETO" >> "$CONF/evento.env"
  echo "    Se generó EVENTO_SECRETO. Guarde una copia en un lugar seguro (gestor de secretos de TI)."
fi

echo "==> Servicios systemd"
install -m 644 "$ORIGEN/deploy/evento-coopetrol.service" /etc/systemd/system/
install -m 644 "$ORIGEN/deploy/evento-coopetrol-respaldo.service" /etc/systemd/system/
install -m 644 "$ORIGEN/deploy/evento-coopetrol-respaldo.timer" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now evento-coopetrol.service evento-coopetrol-respaldo.timer
systemctl restart evento-coopetrol.service

sleep 2
PUERTO=$(grep -E '^PORT=' "$CONF/evento.env" | cut -d= -f2); PUERTO=${PUERTO:-3000}
if curl -fsS "http://127.0.0.1:$PUERTO/api/cupos" >/dev/null; then
  echo "==> El servicio responde en http://127.0.0.1:$PUERTO"
else
  echo "==> El servicio no responde. Revise: journalctl -u evento-coopetrol -n 50"
  exit 1
fi

if [ "$PRUEBAS" = 1 ]; then
  IP=$(hostname -I | awk '{print $1}')
  cat <<EOF

Ambiente de pruebas listo:
  Aplicación: http://$IP:$PUERTO/
  Panel:      http://$IP:$PUERTO/admin.html   (cree el usuario: sudo bash $APP/deploy/admin.sh usuario "Nombre")
  Documentos de prueba: ver README (p. ej. 1001 con fecha de expedición 14/03/2008).
  Si no abre desde otro equipo, permita el puerto en el firewall: sudo ufw allow from 192.168.0.0/16 to any port $PUERTO
EOF
  exit 0
fi

cat <<EOF

Siguientes pasos:
  1. HTTPS:   sudo bash $ORIGEN/deploy/configurar_nginx.sh SU_DOMINIO correo@coopetrol.coop
  2. Admin:   sudo bash $APP/deploy/admin.sh usuario "Nombre Apellido"   (los demás usuarios, desde el panel)
  3. Claves:  guarde EVENTO_SECRETO y RESPALDO_CLAVE de $CONF/evento.env en el gestor de secretos
  4. Panel:   restrinja el acceso con PANEL_REDES en $CONF/evento.env (p. ej. 10.0.0.0/8,192.168.0.0/16)
  5. Revise:  $APP/data/config.json  (validar_periodo_inscripcion y fecha_supresion_datos)
EOF
