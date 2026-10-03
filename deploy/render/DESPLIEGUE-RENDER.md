# Despliegue en Render

Render publica la aplicación con HTTPS en una dirección propia (`https://evento-coopetrol.onrender.com`) o en un
subdominio de Coopetrol, sin depender del firewall de la empresa. La configuración está en `render.yaml` (Blueprint).

## Requisitos y costo

- Cuenta en [render.com](https://render.com) con acceso al repositorio privado `Coopetrol-tec/simulador-eventos` en GitHub.
- **Plan Starter** (aprox. US$7/mes) **más un disco persistente** de 1 GB (aprox. US$0,25/mes). El plan gratuito no sirve:
  no admite disco, se apaga tras 15 minutos sin uso y **borra la base de datos y los soportes** en cada reinicio.

## 1. Generar la clave de respaldos

En su equipo (PowerShell), una sola vez. Guarde el valor en un lugar seguro (gestor de contraseñas):

```powershell
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

## 2. Crear el servicio

1. En Render: **New → Blueprint** → elija el repositorio `simulador-eventos` (rama `main`).
2. Render lee `render.yaml` y pide los valores de:
   - `RESPALDO_CLAVE`: pegue la clave del paso 1.
   - `PANEL_REDES`: déjelo vacío (o la IP pública de Coopetrol, p. ej. `181.79.218.42/32`, para que el panel solo abra
     desde la red de la empresa).
3. **Apply**. Render crea el servicio `evento-coopetrol` con su disco en `/var/data` y genera `EVENTO_SECRETO`.
4. Cuando el despliegue termine (estado *Live*), abra `https://evento-coopetrol.onrender.com`.

Guarde también una copia de `EVENTO_SECRETO` (servicio → **Environment**): sin ella no se validan las fechas de
expedición cargadas ni se pueden migrar los datos a otro servidor.

## 3. Primer usuario del panel

Servicio → **Shell**:

```bash
node scripts/crear_admin.js dreina "Diego Reina"                  # administrador (pide la clave)
node scripts/crear_admin.js tesoreria "Tesorería" --rol revisor   # revisor
```

Panel: `https://evento-coopetrol.onrender.com/admin.html` → **Cupos y cargas masivas** para cargar asociados y Coopetrolitos.

## 4. Verificar la IP de los clientes (una vez)

Los límites de intentos por IP y el registro de seguridad dependen de leer bien la IP del visitante. Ingrese al panel y
revise en **Logs** la línea `"evento":"login_ok"`: el campo `ip` debe ser su IP pública (consúltela en
https://www.cual-es-mi-ip.net). Si muestra otra (p. ej. una IP del proxy de Render), cambie `IP_SALTOS` a `2` en
**Environment** y vuelva a probar.

## 5. Configuración del evento

`data/config.json` del repositorio (fechas de inscripción, enlace de pago, `validar_periodo_inscripcion`, fecha de
supresión), `data/tarifas.json`, `data/agencias_evento.json`, `data/formulario_soporte.json` y `data/habeas_data.json`.
Para cambiarlos: edítelos, haga `git push` y en Render **Manual Deploy → Deploy latest commit**. Los datos del disco
(base, soportes) se conservan; las migraciones de la base corren solas al arrancar.

Antes de abrir inscripciones: `"validar_periodo_inscripcion": true` en `data/config.json`.

## 6. Dominio propio (opcional), p. ej. `https://eventos.coopetrol.coop`

Servicio → **Settings → Custom Domains → Add** `eventos.coopetrol.coop`. Render indica un registro **CNAME** que se crea
en el DNS de `coopetrol.coop` (GoDaddy) apuntando a `evento-coopetrol.onrender.com`. El certificado HTTPS lo emite y
renueva Render automáticamente. No requiere cambios en el firewall de la empresa.

## 7. Respaldos y operación

- Render toma **instantáneas diarias** del disco (servicio → **Disks**), que permiten restaurarlo.
- Respaldo cifrado manual (queda en `/var/data/respaldos`): Shell → `node scripts/respaldo.js`.
- Exporte periódicamente las inscripciones a Excel desde el panel y guárdelas en los sistemas de Coopetrol.
- Limpiar datos de prueba: Shell → `node scripts/limpiar.js todo --confirmar`.
- Registros de la aplicación y de seguridad: servicio → **Logs** (filtre por `"tipo":"seguridad"`).

## Datos personales

Render aloja los datos en Estados Unidos (región Virginia). Es una **transferencia internacional de datos personales**
(Ley 1581 de 2012, art. 26): el área jurídica debe validarla y, si corresponde, mencionar en la autorización de
tratamiento (`data/habeas_data.json`) que los datos se almacenan con un proveedor de nube en el exterior.
