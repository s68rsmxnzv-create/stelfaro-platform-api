# Publicación de agentes

Los binarios de este directorio no se guardan en Git. Se publican con:

```bash
php scripts/publish-agent-release.php windows /ruta/agente.zip 1.0.0
php scripts/publish-agent-release.php android /ruta/agente.apk 1.0.0
```

El comando conserva una copia versionada, reemplaza de forma atómica el archivo
`latest` de la plataforma y actualiza `agent-releases.json`.
