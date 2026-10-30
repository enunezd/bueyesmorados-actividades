# Tigres Azules · Promo 63 — Actividades

Sistema web para que las familias de la promoción se inscriban en las actividades (por ejemplo el Bingo),
eligiendo **comisión** y **turno**, y para que la directiva lo administre. Hecho en PHP + MySQL (sin dependencias).

- Sitio público: https://tigresazules63.com/actividades/
- Administración: https://tigresazules63.com/actividades/admin/

## Qué hace

**Las familias**
- Ven las actividades abiertas, eligen comisión y turno (con cupos visibles) e identifican su familia buscándola en la lista.
- Reciben un código; con él pueden cancelar mientras la inscripción siga abierta y agregar el turno a su calendario.
- «Consultar mi turno»: ven en qué turno quedó su familia (inscrita por ellas o asignada por sorteo).

**La directiva**
- Crea actividades, comisiones, turnos y cupos con ayudas en cada paso; puede copiar la estructura de otra actividad.
- Gestiona la lista de familias (importar CSV/pegado, buscar, activar/desactivar).
- Ve, mueve, cancela e inscribe familias manualmente; avisa por WhatsApp; exporta a CSV.
- **Asignación automática**: al cerrarse la inscripción por fecha, las familias de la lista que no se inscribieron se reparten
  **al azar** en los lugares libres (llenando primero los turnos más vacíos). Hay vista previa, se puede deshacer y exonerar familias.

## Asignación automática (cómo funciona)
1. En la actividad: «Según fechas» → marcar «Asignar automáticamente al cerrar» (o desde la pestaña *Asignación automática*).
2. Al pasar la fecha de cierre, el sistema sortea **una sola vez** (se ejecuta con la primera visita al sitio o con el cron, lo que ocurra primero).
3. Cada sorteo guarda su *semilla* (auditable: la misma semilla da el mismo reparto) y queda en la auditoría.
4. Las asignaciones se pueden mover/cancelar en *Inscripciones*, o deshacer todas desde *Asignación automática*.

Tarea programada opcional (más puntual; sin ella igual funciona al primer acceso tras el cierre).
En hPanel → Avanzado → Cron Jobs, cada 5 minutos:
```
/usr/bin/php /home/u340939578/domains/tigresazules63.com/public_html/actividades/cli/asignar-pendientes.php
```

## Estructura
- `index.php`, `actividad.php`, `registrar.php`, `confirmacion.php`, `mi-turno.php`, `calendario.php`, `buscar-familia.php`: sitio público.
- `admin/`: panel (Inicio, Actividades → Resumen / Comisiones y turnos / Inscripciones / Asignación / Datos, Familias, Exportar).
- `includes/`: configuración, conexión, módulos (`asignacion.php`, `familias.php`, `actividades.php`, `migraciones.php`). Bloqueado desde la web.
- `cli/`: `migrar.php` (actualiza la base), `asignar-pendientes.php` (cron). Bloqueado desde la web.
- `sql/schema.sql`: esquema completo para una instalación nueva.
- `dev/`: entorno local con Docker.

## Configuración
Copia `includes/config.example.php` a `includes/config.php` y completa los datos de la base.
**`config.php` nunca se sube a git** (ver `.gitignore`). El nombre interno de la base en el hosting
(`u340939578_bueyes_actv`) es histórico y solo se cambia desde hPanel; no afecta al sitio.

## Desarrollo local
```
docker compose -f dev/docker-compose.yml up -d --build
```
Web: http://localhost:8088 · Admin: `/admin/` con usuario `admin` / clave `admin123` (solo local). Usa PHP 8.0 y MariaDB 11.8, igual que el hosting.

## Despliegue (SSH)
1. **Respaldar** archivos y base (`tar` + `mysqldump`).
2. Subir solo el código; **no** sobrescribir `includes/config.php`.
3. `php cli/migrar.php` — aplica los cambios de base (solo agrega; se puede ejecutar varias veces).
4. Revisar el sitio y el panel.

## Privacidad
- La búsqueda pública de familia devuelve solo el apellido y la sección, nunca teléfonos, y limita las consultas por sesión.
- Los teléfonos se piden al inscribirse y solo los ve la directiva.
- Las claves de `credenciales2026.csv` de otras aplicaciones **no** se usan ni se importan.
