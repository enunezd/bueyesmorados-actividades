# Bueyes Morados · Inscripción de familias

Sistema en PHP + MySQL para que las familias se inscriban en actividades (por ejemplo el Bingo),
eligiendo **comisión** y **turno**. Incluye un panel de administración.

- Sitio: https://tigresazules63.com/bueyes-morados/
- Administración: https://tigresazules63.com/bueyes-morados/admin/

## Cómo se organiza una actividad
1. **Actividades** → «Nueva actividad» (nombre, fecha, cuándo se abre la inscripción).
2. **Comisiones y turnos** → agrega las comisiones (una por línea) y los turnos.
   Los cupos se crean solos para cada comisión × turno; solo ajustas el número de familias.
3. **Publicar** la actividad para que aparezca en la página pública.

Una comisión o turno con inscripciones no se puede eliminar (se puede ocultar).

## Estructura
- `index.php`, `actividad.php`, `registrar.php`, `confirmacion.php`: sitio público.
- `admin/`: panel de administración.
- `includes/`: configuración, conexión y funciones (bloqueado desde la web por `.htaccess`).
- `sql/schema.sql`: tablas de la base de datos.
- `dev/`: entorno local con Docker.

## Configuración
Copia `includes/config.example.php` a `includes/config.php` y completa los datos de la base.
**`config.php` nunca se sube a git** (ver `.gitignore`).

## Desarrollo local
```
docker compose -f dev/docker-compose.yml up -d --build
```
Web: http://localhost:8088 · Admin: `/admin/` con usuario `admin` / clave `admin123` (solo local).
Usa PHP 8.0, igual que el hosting.

## Despliegue
Subir por SSH solo los archivos de código; **no** sobrescribir `includes/config.php` ni tocar la base de datos.
Antes de cada cambio grande, respaldar archivos y ejecutar `mysqldump`.
