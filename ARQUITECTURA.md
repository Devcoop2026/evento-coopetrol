# Arquitectura

Aplicación **Laravel 13** (PHP 8.4) con **Livewire 4** y **PostgreSQL**, organizada en capas según Clean Architecture.
La regla de dependencias es una sola: **el código solo depende hacia adentro**.

```
Presentación        app/Livewire, app/Http, resources/views, public/   (Livewire, controladores de descarga, middleware)
      │
      ▼
Aplicación          app/Aplicacion      casos de uso; definen puertos propios (LectorHojaCalculo, AutenticadorPanel)
      │
      ▼
Dominio             app/Dominio         reglas de negocio puras: sin Laravel, sin base de datos, sin HTTP
      ▲
      │ implementa los puertos
Infraestructura     app/Infraestructura, app/Models   Eloquent/SQL, almacenes, HMAC, caché, Excel, cifrado

Composición         app/Providers/AppServiceProvider.php   une cada puerto con su adaptador
Consola             app/Console/Commands (evento:*)           usa los casos de uso y la infraestructura
```

- **Dominio** (`app/Dominio`): `Inscripcion` (estados y transiciones, cupos, referencia `EVTaa-nnnnnn`, acompañantes,
  medios de pago, campos adicionales del soporte), `Padron` (asociado, Coopetrolito, tarifa), `Panel` (roles, usuarios,
  auditoría), `Seguridad` (huella de la fecha de expedición, límites, enmascarado) y `Compartido` (`ErrorValidacion`,
  `Valores`, `Reglas` de los tipos de campo, `Reloj`, `UnidadDeTrabajo`). Aquí viven las **interfaces** (puertos) que
  la infraestructura implementa: repositorios, `AlmacenComprobantes`, `HuellaFecha`, `LimitadorIntentos`, `HashClaves`…
- **Aplicación** (`app/Aplicacion`): un caso de uso por clase (`Preinscribir`, `InscribirConPago`, `RegistrarPago`,
  `ModificarInscripcion`, `CancelarInscripcion`, `ConsultarInscripcion`, `RevisarInscripcion`, `AjustarCupos`,
  `ExportarInscripciones`, `CargarBase`, `GestionUsuarios`…). Orquestan el dominio y los puertos; no conocen Eloquent
  ni Livewire. `ConfiguracionEvento` reúne los JSON de `data/` y los parámetros del entorno.
- **Infraestructura** (`app/Infraestructura`, `app/Models`): repositorios Eloquent/SQL (`Persistencia`), almacenes de
  comprobantes (`Almacenamiento`), lectura de los JSON y sincronización de tarifas (`Configuracion`), lector de Excel y
  CSV sin dependencias (`Excel`), HMAC, limitador sobre la caché, autenticación con el guard de Laravel (`Seguridad`) y
  cifrado de respaldos (`Respaldo`). Los modelos Eloquent son detalles de persistencia: no salen de esta capa hacia el
  dominio (los repositorios devuelven objetos del dominio o arreglos).
- **Presentación**: componentes Livewire (estado de la pantalla y llamadas a los casos de uso), vistas Blade, el
  controlador de descargas del panel y el middleware de cabeceras de seguridad, red del panel y recarga de tarifas.

## SOLID en el código

| Principio | Dónde |
|---|---|
| **S** — responsabilidad única | Un caso de uso por clase en `app/Aplicacion` (p. ej. `Preinscribir` solo preinscribe). El lector de Excel solo lee, `AnalizarBase` solo valida y `CargarBase` solo guarda |
| **O** — abierto/cerrado | Estrategias `TipoAcompanante` (`AcompananteAsociado`, `AcompananteCoopetrolito`, `AcompananteInvitado`) que recorre `ClasificadorAcompanantes`, y `MedioPago` (`MedioPagoPse`, `MedioPagoAgencia`) registradas en `CatalogoMediosPago`: un tipo o medio nuevo es una clase nueva, sin tocar los casos de uso |
| **L** — sustitución de Liskov | `AlmacenComprobantesBaseDatos` y `AlmacenComprobantesDisco` (disco local, S3 o Cloudflare R2) son intercambiables detrás de `AlmacenComprobantes` (`SOPORTES_ALMACEN`) |
| **I** — segregación de interfaces | Repositorios separados por uso: `RepositorioPadron` (lectura para identificar y liquidar), `RepositorioGestionPadron` (CRUD del panel) y `RepositorioBases` (cargas masivas) |
| **D** — inversión de dependencias | Los casos de uso reciben puertos (`RepositorioInscripciones`, `UnidadDeTrabajo`, `Reloj`, `HuellaFecha`…); `AppServiceProvider` los une con sus adaptadores. Las pruebas pueden reemplazar cualquiera (p. ej. un `RegistroSeguridad` en memoria) |

## Flujo de una inscripción

1. **Livewire** (`App\Livewire\Portal\Inscripcion`): el asociado digita documento, fecha de expedición, evento y
   acompañantes. La acción del componente llega por la ruta de actualización de Livewire (CSRF y límite de 600
   solicitudes cada 5 minutos por IP, `RateLimiter` `publico`).
2. **`ProteccionIdentidad`** envuelve las acciones que validan documento + fecha: si la IP superó los fallos permitidos
   responde "demasiados intentos"; si el caso de uso falla por identidad, cuenta el fallo y lo deja en el registro de
   seguridad con el documento enmascarado.
3. **Caso de uso** (`Preinscribir` o `InscribirConPago`): `IdentificarAsociado` compara el HMAC de la fecha
   (`HuellaFecha`) y bloquea el documento tras 10 fallos; `SimularInscripcion` clasifica a los acompañantes y liquida con
   la tarifa del evento; `PoliticaInscripcion` exige la autorización de habeas data y el periodo de inscripción.
4. **`UnidadDeTrabajo`** (`UnidadDeTrabajoBaseDatos`) abre una transacción y toma `pg_advisory_xact_lock`: las
   operaciones que validan y ocupan cupos se ejecutan de una en una, así dos pagos simultáneos no superan el cupo ni
   inscriben dos veces a la misma persona. El bloqueo es **de transacción** (se libera al terminar), por eso funciona
   con el pooler de Neon (PgBouncer en modo transacción).
5. **Repositorios** (`RepositorioInscripcionesEloquent`) guardan la inscripción, sus personas y el soporte; el
   comprobante va al `AlmacenComprobantes`. La referencia `EVTaa-nnnnnn` sale del id.
6. El componente muestra la confirmación y emite `cupos-actualizados` para refrescar los contadores.

Los errores de negocio son `ErrorValidacion` (mensaje para el usuario); los componentes los muestran en el formulario y,
fuera de Livewire, `bootstrap/app.php` los traduce a 422 (y `PermisoDenegado` a 403, `DemasiadosIntentos` a 429).

## Presentación con Livewire

Sin compilación de recursos (no hay Vite ni npm): `public/styles.css` y los módulos ES de `public/js/` (validación por
tipo de campo, diálogos, notificaciones) se sirven tal cual. Livewire usa la compilación **`csp_safe`** de Alpine (sin
`eval`) y toma el *nonce* de la política de contenido de cada solicitud (`CabecerasSeguridad`).

| Componente | Ruta / uso |
|---|---|
| `Portal\Portal` | Página completa `/` (layout `layouts.portal`): elige el módulo (`#pse`, `#agencia`, `#consulta`); los hijos siguen montados al cambiar de vista |
| `Portal\Inscripcion` | Módulos PSE (preinscripción) y agencia (inscripción con pago en un paso) |
| `Portal\Consulta` | "Consulte su inscripción": estado, registrar el pago, modificar o cancelar |
| `Portal\ContadorCupos` | Valores y cupos disponibles del evento elegido (cada 30 segundos) |
| `Portal\TablaTarifas` | Tarifas y cupos por evento |
| `Formularios\FormularioPago` | Formulario de pago (objeto `Form` de Livewire) compartido por el módulo agencia y la consulta |
| `Panel\Ingreso` | `/admin/ingreso` (layout `layouts.panel`) |
| `Panel\Panel` | `/admin`: pestañas Inscripciones, Asociados, Coopetrolitos, Usuarios del panel y Cupos y cargas masivas; las de gestión solo para el administrador |
| `Panel\ResumenCupos` | Indicadores de cupos del panel |
| `Panel\Inscripciones` | Revisión de pagos: listado con filtros, detalle, aprobar/rechazar (y anular, solo administrador) |
| `Panel\Gestion` | CRUD de asociados, Coopetrolitos o usuarios según `tipo` |
| `Panel\Auditoria` | Registro de cambios manuales (junto a usuarios) |
| `Panel\Cupos` | Cupos por evento y ajuste del cupo vigente |
| `Panel\Base` | Carga masiva de asociados o Coopetrolitos con vista previa |

Comparten comportamiento con *traits* en `app/Livewire/Concerns` (`ConMensajes`, `ConConfirmacion`, `ConPermisos`,
`ConFormularioPago`). Las descargas (comprobante, exportación CSV y plantillas) las atiende
`Http\Controllers\Panel\ControladorDescargas`.

## Cómo extender

| Cambio | Dónde |
|---|---|
| Nuevo tipo de acompañante | Una clase que implemente `Dominio\Inscripcion\Acompanantes\TipoAcompanante` (`aplica`, `validar`, `valor`, `ocupaCupo`…) agregada a la lista de `ClasificadorAcompanantes` (antes de `AcompananteInvitado`, que aplica siempre) y su opción en la vista de acompañantes |
| Nuevo medio de pago | Una clase que implemente `Dominio\Inscripcion\Pagos\MedioPago` agregada a `CatalogoMediosPago` y su opción en `resources/views/livewire/partials/formulario-pago.blade.php` |
| Nuevo campo del formulario de pago | Solo `data/formulario_soporte.json` (tipos: texto, numero, numerico, telefono, correo, fecha, seleccion, placa; `mostrarSi`) |
| Nueva columna o tabla | Una migración nueva en `database/migrations` (nunca editar una ya aplicada en producción); se aplica sola al desplegar (`migrate --force`) |
| Nueva pantalla o sección | Un componente Livewire (clase en `app/Livewire`, vista en `resources/views/livewire`) que llame a un caso de uso |
| Nueva regla de un campo | `app/Dominio/Compartido/Reglas.php` y la misma en `public/js/validacion.js` |
| Nuevo comando de operación | Una clase en `app/Console/Commands` con firma `evento:...` (Laravel la registra sola) |

## Decisiones

- **PostgreSQL en Neon**: base administrada y gratuita para el volumen del evento, con restauración a un momento
  anterior. Render (plan gratuito) no tiene disco persistente, por eso todo el estado vive en la base: inscripciones,
  **comprobantes** (`soportes_archivos`, `bytea`), sesiones y caché de los límites.
- **Comprobantes en la base** por defecto (`SOPORTES_ALMACEN=base_datos`): entran en los respaldos de Neon y en
  `evento:respaldo`. Si el almacenamiento se queda corto, un disco S3 (Cloudflare R2) se configura sin cambiar código.
- **Fecha de expedición como HMAC** (`EVENTO_SECRETO`), nunca en texto plano; mismo cálculo que la versión anterior,
  así sus datos se pueden migrar (`evento:importar-sqlite`).
- **El cupo se ocupa al pagar**: la preinscripción no bloquea cupos; el formulario de pago válido sí.
- **Livewire sin compilación**: menos piezas que mantener (sin Node.js); la CSP estricta se conserva con el *nonce*.
- **Tarifas desde JSON**: `data/tarifas.json` (generado desde el Excel) se recarga en la base cuando cambia su firma.
- **Marcas de tiempo en UTC** (`timestamptz`); la interfaz las muestra en hora de Colombia.

## Pruebas

`php artisan test` (PHPUnit sobre la base PostgreSQL `postgres_evento_test`, `RefreshDatabase`):

- `tests/Unit/Dominio`: reglas puras (valores, acompañantes, medios de pago, estados).
- `tests/Unit/Infraestructura`: cifrado de respaldos.
- `tests/Feature`: casos de uso sobre la base (simulador, inscripciones, pagos, revisión y exportación, gestión,
  bases, usuarios, seguridad, migraciones, HTTP).
- `tests/Feature/Livewire`: componentes del portal y del panel.
- `tests/Feature/Comandos`: comandos `evento:*` (usuarios, cargas, tarifas, respaldo y limpieza, importación de la
  versión anterior). Las de respaldo se omiten si no hay `pg_dump`.
