# Stelfaro Platform API

Backend Laravel de plataforma para Stelfaro.

Esta pieza concentra seguridad y resolución de plataforma:

- usuarios
- tenants
- membresías usuario-tenant
- catálogo de apps
- apps habilitadas por tenant
- app principal/default por tenant
- endpoint de sesión para que el portal decida a dónde enviar al usuario

## Responsabilidades

Este servicio no es el motor fiscal. Para emisión DTE debe actuar como backend de negocio y consumir `dte-core` por APIs internas.

```text
Frontend apps -> Stelfaro Platform API -> dte-core / notifications / print
```

## Endpoints iniciales

```text
GET /api/v1/health
GET /api/v1/me
```

`GET /api/v1/me` requiere autenticación y devuelve:

- usuario
- tenant activo
- apps disponibles
- app default
- URL de redirección sugerida

## Seeder local

El seeder crea:

- app `taller` -> `taller.stelfaro.com`
- app `facturacion` -> `facturacion.stelfaro.com`
- tenant demo `servicio-tecnico-el-faro`
- usuario demo `owner@stelfaro.test`

## Desarrollo

```bash
composer install
php artisan migrate:fresh --seed
php artisan serve
php artisan test
```
