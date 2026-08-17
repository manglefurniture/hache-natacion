# Estándar de reportes — Hache Natación

## Regla general

Todo reporte administrativo o financiero nuevo de Hache Natación debe poder exportarse a PDF con identidad visual Hache, además de conservar CSV cuando aplique para auditoría/datos.

El PDF nunca debe estar codificado para una sede específica. Debe resolverse por `sede + periodo` y tomar de la base de datos el nombre de la sede, socio/convenio, porcentajes de distribución y mínimo mensual configurados.

## Identidad PDF

- Encabezado `Hache Natación`.
- Nombre de la sede y periodo claramente visibles.
- Azul institucional oscuro, fondos blancos/gris muy claro y tipografía limpia.
- Resumen ejecutivo primero; detalle después.
- Importes en MXN.
- Fecha/hora de generación en zona `America/Cancun`.
- Nombre de archivo: `Hache_Natacion_Reporte_<SEDE>_<AAAA-MM>.pdf`.

## Contenido mínimo del reporte mensual

1. Total cobrado.
2. Mensualidades.
3. Inscripciones.
4. Cursos intensivos.
5. Participación de Hache y del socio de la sede.
6. Mínimo contractual, cuando exista, y estado alcanzado/pendiente.
7. Asistencia del periodo.
8. Detalle de movimientos válidos.
9. Nota de reglas financieras utilizadas.

## Regla para Palapas y futuras sedes

Palapas debe usar el mismo generador y la misma estructura visual. Sus porcentajes, nombre de socio, mínimo contractual y cualquier regla financiera deben salir de la configuración de la sede, nunca escribirse directamente en la plantilla PDF.

Las futuras sedes deben heredar esta misma regla.

## Implementación vigente

- Vista: `public/reportes.php`
- Datos: `api/reportes.php`
- CSV: `api/reportes-exportar.php`
- PDF: `api/reportes-pdf.php`
- Motor PDF: `dompdf/dompdf`
