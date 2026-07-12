# Continuidad y contingencia

Este plan define la respuesta minima ante interrupciones operativas del gestor.

## Escenarios

- Caida o lentitud de la plataforma.
- Caida o lentitud del core fiscal.
- Indisponibilidad de servicios MH.
- Error de firma electronica.
- Falla de correo o notificaciones.
- Perdida parcial de conectividad del usuario.

## Respuesta operativa

1. Confirmar alcance: plataforma, core fiscal, MH, red local o servicio externo.
2. Revisar logs de aplicacion, colas, base de datos y respuestas de servicios externos.
3. Evitar reintentos manuales repetidos si existe una transmision pendiente o idempotente.
4. Si MH no esta disponible y la normativa lo permite, operar bajo el flujo de contingencia.
5. Registrar fecha, hora, usuarios afectados, documentos afectados y acciones realizadas.
6. Comunicar al responsable operativo el estado y la proxima accion.

## Recuperacion

- Restaurar servicio desde infraestructura primaria cuando sea posible.
- Procesar colas pendientes y verificar correos/reenvios.
- Validar DTE/eventos pendientes contra su estado en core y MH antes de retransmitir.
- Documentar causa raiz y medida preventiva.

## Evidencia minima

- Hora de inicio y fin.
- Modulos afectados.
- Documentos o eventos involucrados.
- Usuario o responsable que reporto.
- Acciones tomadas y resultado.
