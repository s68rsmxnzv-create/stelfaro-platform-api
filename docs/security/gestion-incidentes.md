# Gestion de incidentes

Un incidente es cualquier evento que comprometa o pueda comprometer disponibilidad, integridad, confidencialidad o trazabilidad de la informacion.

## Clasificacion

- Baja: afecta a un usuario o flujo no critico, sin exposicion de datos.
- Media: afecta emision, inventario, catalogo, clientes o disponibilidad parcial.
- Alta: afecta documentos fiscales, credenciales, datos personales o disponibilidad general.
- Critica: exposicion confirmada de secretos, datos sensibles, fraude, perdida de informacion o compromiso de infraestructura.

## Proceso

1. Registrar el incidente con hora, reportante, modulo y descripcion breve.
2. Preservar evidencia: auditoria, logs, respuestas externas, capturas y acciones realizadas.
3. Contener el alcance: suspender accesos, rotar credenciales o pausar integraciones si aplica.
4. Corregir la causa y validar con prueba controlada.
5. Comunicar el resultado al responsable operativo.
6. Cerrar con causa raiz, impacto, acciones preventivas y fecha de seguimiento.

## Evidencia tecnica disponible

- `platform_audit_logs` para actividad de plataforma.
- `security_events` para entradas sospechosas bloqueadas.
- Trazas de documentos, eventos y transmisiones en `dte-core`.
- Logs de aplicacion y colas.

## Datos que no deben exponerse en reportes

- Contrasenas.
- Tokens y cookies.
- Certificados o llaves privadas.
- Payloads fiscales completos cuando no sean necesarios para diagnostico.
