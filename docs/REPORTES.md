# Estándar de reportes — Hache Natación

## Regla general

Todo reporte administrativo o financiero nuevo de Hache Natación debe poder exportarse a PDF con identidad visual Hache, además de conservar CSV cuando aplique para auditoría/datos.

El PDF nunca debe estar codificado para una sede específica. Debe resolverse por `sede + periodo` y tomar de la base de datos el nombre de la sede, socio/convenio, porcentajes de distribución y mínimo mensual configurados.

## Identidad PDF

- Encabezado `Hache Natación`.
- Nombre de la sede y periodo claramente visibles.
- Azul institucional oscuro, fondos blancos/gris muy claro y tipografía limpia.
- Resumen ejecutivo primero; detalle financiero agrupado después.
- Importes en MXN.
- Fecha/hora de generación en zona `America/Cancun`.
- Nombre de archivo: `Hache_Natacion_Reporte_<SEDE>_<AAAA-MM>.pdf`.

## Contenido del reporte mensual

1. Resumen: total cobrado, mensualidades, inscripciones e intensivos.
2. Participación de Hache y del socio de la sede.
3. Mínimo contractual, cuando exista, y estado alcanzado/pendiente.
4. Bloque **Nuevas inscripciones**: una fila por alumno, mostrando únicamente nombre, importe de inscripción e importe de mensualidad del mismo periodo.
5. Bloque **Mensualidades**: alumnos regulares que pagaron mensualidad y no aparecen como nueva inscripción del periodo; mostrar únicamente nombre e importe de mensualidad.
6. Bloque **Cursos intensivos**: separar los alumnos por fecha de inicio del curso; dentro de cada fecha mostrar únicamente nombre e importe pagado por intensivo.
7. Nota breve de las reglas financieras utilizadas.

El PDF es un documento de lectura y liquidación. No debe mostrar folio, método de pago ni otros campos técnicos que no sean necesarios para entender la liquidación. El CSV conserva el detalle completo para auditoría.

## Regla para Palapas y futuras sedes

Palapas debe usar el mismo generador, la misma estructura visual y los mismos tres bloques financieros. Sus porcentajes, nombre de socio, mínimo contractual y cualquier regla financiera deben salir de la configuración de la sede, nunca escribirse directamente en la plantilla PDF.

Las futuras sedes deben heredar esta misma regla.

## Implementación vigente

- Vista: `public/reportes.php`
- Datos: `api/reportes.php`
- CSV: `api/reportes-exportar.php`
- PDF: `api/reportes-pdf.php`
- Motor PDF: `dompdf/dompdf`
