# Pull Request Template

¡Gracias por contribuir!

## Descripción
- ¿Qué cambio se propone?
- ¿Qué funcionalidades afectan?

## Checklist de PR
- [ ] Incluye nuevas migraciones.
- [ ] La migración agrega un índice a la columna `reprocesado` en `orden_detalles`.
- [ ] Se añadió la nueva columna `reprocesado` al modelo `OrdenDetalle` y se agregó el cast.
- [ ] Se escribieron pruebas que verifican el casting y el comportamiento por defecto.
- [ ] Se actualizó la documentación del proyecto para reflejar el nuevo campo.
- [ ] El PR pasa todos los tests locales.
- [ ] No introduce nuevos archivos binarios ni dependencias.

## ¿Necesita revisión? 
- Por favor, asigna a un reviewer de Laravel o a un miembro del equipo.
- Si es una merge squash, indica la rama destino.
