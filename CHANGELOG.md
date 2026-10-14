# Changelog

Todas as mudanças relevantes deste pacote. Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/); versões seguem [SemVer](https://semver.org/lang/pt-BR/).

## [0.1.0] - 2026-10-13

Primeira versão.

### Adicionado

- `Apipj\Apipj` com `consultar` (formatos `completo` e `simplificado`; `404` vira `null`), `validar`, `status`, `uso`, `planos` e `consultarVarios` (lote sequencial com limite por minuto lido de `X-RateLimit-*` ou `rpm`, deduplicação e CNPJs inválidos pulados sem chamar a API).
- Repetição automática: `429 rate_limited` espera `Retry-After` (até 3 tentativas); `5xx`, falha de rede e tempo esgotado com espera de 1 s, 2 s, 4 s. `429 quota_exceeded` lança na hora, com links para assinar os planos Dev e Pro.
- `Apipj\ApipjException` com `status`, `erro`, `retryAfter`, `upgradeUrl`, `reset` e `cnpj`.
- Utilitários offline em `Apipj\Cnpj`: `valido`, `normalizar`, `formatar`, `validarLocal`, `calcularDv` (inclusive CNPJ alfanumérico); números que perderam os zeros à esquerda são completados até 14 dígitos.
- Transporte HTTP com a extensão curl, sem dependências, substituível pela opção `transporte`.

[0.1.0]: https://github.com/apipj/apipj-php/releases/tag/v0.1.0
