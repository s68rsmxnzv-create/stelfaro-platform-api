# Seguridad operativa

Este documento resume los controles tecnicos y operativos que soportan la seguridad del gestor de facturacion electronica.

## Controles vigentes

- Autenticacion de usuarios con sesion de plataforma y expiracion por inactividad.
- Revocacion de sesion fiscal/core al cerrar sesion o al expirar la sesion de plataforma.
- Cabeceras de seguridad HTTP, incluyendo CSP, proteccion contra MIME sniffing y politica de referrer.
- Bloqueo de entradas sospechosas para reducir riesgo de XSS en formularios y APIs de plataforma.
- Auditoria de actividad de plataforma para operaciones de escritura y eventos de autenticacion.
- Trazabilidad fiscal en core para documentos, eventos, transmisiones, respuestas MH y errores.
- Separacion entre plataforma SaaS y core fiscal: el core conserva la verdad fiscal y no contiene logica SaaS.

## Auditoria

La tabla `platform_audit_logs` registra cambios humanos relevantes: usuario, empresa, ruta, metodo, resultado, IP, agente de usuario, hash de sesion y claves de entrada no sensibles.

No se almacenan contrasenas, tokens, cookies ni payloads completos. Para incidentes fiscales, se debe complementar con las trazas propias de `dte-core`.

## Informacion sensible

Las credenciales, tokens internos, llaves de firma, configuracion de correo y secretos de integracion deben mantenerse fuera del repositorio y administrarse por variables de entorno o almacenamiento seguro equivalente.

Los logs no deben incluir archivos DTE completos, certificados, contrasenas, tokens, cookies ni llaves privadas.

## Revision periodica

- Revisar usuarios activos, roles y accesos al menos una vez al mes.
- Revisar eventos de seguridad y auditoria cuando se detecte actividad anomala.
- Verificar respaldos y restauracion con una prueba controlada.
- Confirmar vigencia de certificados, tokens internos y credenciales de servicios externos.
