# 0002. Excluir las retenciones de alquileres (IRPFA)

- Fecha: 2026-07-17
- Commits: `47e1e8b`
- Estado: vigente

## Contexto

Las retenciones de alquileres corresponden al modelo 115, no al 111.

## Decisión

Las subcuentas con cuenta especial IRPFA (en la subcuenta o en su cuenta) se excluyen en `loadEntryLines`. El usuario debe asignarla; sin ella no hay forma de separarlas (`hasRentalSubaccounts` lo comprueba para avisar).

## Consecuencias

Una instalación sin IRPFA incluye los alquileres en el 111.
