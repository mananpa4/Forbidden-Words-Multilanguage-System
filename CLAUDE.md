# CLAUDE.md

Guía permanente de trabajo para `Forbidden-Words-Multilanguage-System`. Este archivo contiene solo lo estable: qué es el proyecto, cómo está montado y cómo se trabaja en él. El estado cambiante, las decisiones recientes y lo pendiente viven en `CLAUDE_CONTEXT.md`.

## Inicio obligatorio de cada sesión

1. Lee este archivo completo.
2. **Revisa siempre `CLAUDE_CONTEXT.md` antes de realizar cambios importantes.**
3. Ejecuta las pruebas antes de tocar nada, para saber de qué estado partes.
4. Después de una tarea importante o una decisión técnica relevante, actualiza `CLAUDE_CONTEXT.md`.

## Proyecto

Librería PHP que filtra palabras prohibidas en varios idiomas. Nació para dejar de mantener la misma lógica copiada en tres sitios del ecosistema Satella:

- `chismy.com` — WoWonder (PHP plano), en `assets/includes/functions_profanity.php`.
- `dowdes.com` — Laravel, en `app/Services/ProfanityFilter.php`.
- `shomex.com.mx` — Laravel, en `app/Services/ProfanityFilter.php`.

Las tres copias divergieron. Esta librería es la implementación de referencia; los sitios deben converger hacia ella.

La librería **no decide políticas**: enmascara texto o responde si contiene algo prohibido. Bloquear una publicación, avisar a un moderador o registrar el intento es responsabilidad de quien la usa.

## Arquitectura

Tres piezas y un principio: separar *qué está prohibido* de *cómo se reconoce* y de *cómo se tapa*.

- `WordSet` — la lista efectiva, en tres capas: `base` (lista heredada del sitio), `extra` (añadidos sin límite) y `removed` (exclusiones). `removed` existe porque en WoWonder la lista base vive en un `VARCHAR(20000)` que no siempre se puede editar con seguridad. Expone además `cjkWords()`, ordenadas de más larga a más corta.
- `Pluralizer` — interfaz que, dada una palabra del texto, devuelve sus **posibles singulares**. Implementaciones por idioma en `src/Language/`.
- `ForbiddenWords` — la fachada: `isForbidden()`, `censor()`, `containsForbidden()` y `mask()` estático.

### Tres decisiones que no se deben revertir sin leer esto

1. **Coincidencia por palabra completa, no por subcadena.** El enfoque ingenuo (`str_replace`) destroza palabras legítimas: con `as` prohibido, `clase` se convierte en `cl**e`. Se tokeniza con `/\p{L}[\p{L}\p{N}\']*+/u`.

2. **Se deriva el singular del texto, no se pluraliza la lista.** La lista solo declara `casino` y aun así se detecta `casinos`. Hacerlo al revés duplicaría el conjunto en memoria y obligaría a acertar la pluralización de cada entrada, que un humano no escribe de forma regular.

3. **CJK va por subcadena, y es la excepción deliberada al punto 1.** El chino, el japonés y el coreano no separan palabras con espacios, así que la coincidencia por palabra completa nunca dispararía.

### Enmascarado

`mask()` deja visible la primera mitad y tapa el resto: `pendejo` (7) → `pen****`. **Este reparto exacto no se cambia**: los tres sitios ya censuran así y el resultado debe verse igual en todos.

## Tecnologías

- PHP 8.2+, `ext-mbstring`. Sin dependencias de ejecución.
- PSR-4 bajo el namespace `ForbiddenWords\`.
- Pruebas propias, sin PHPUnit (ver *Convenciones*).

## Estructura

```text
Forbidden-Words-Multilanguage-System/
├── src/
│   ├── ForbiddenWords.php          # fachada
│   ├── WordSet.php                 # capas base/extra/removed + CJK
│   ├── Pluralizer.php              # interfaz
│   └── Language/
│       ├── SpanishPluralizer.php
│       └── EnglishPluralizer.php
├── tests/
│   └── run.php                     # banco de pruebas sin dependencias
├── composer.json
├── CLAUDE.md
├── CLAUDE_CONTEXT.md
└── README.md
```

## Comandos

```bash
php tests/run.php        # pruebas; sale con código 1 si algo falla
composer test            # idéntico, si hay composer disponible
composer dump-autoload   # tras añadir clases nuevas
```

## Convenciones de trabajo

- **Todo idioma nuevo es un `Pluralizer` nuevo**, no un `if` dentro de otro. Se registra pasándolo al constructor de `ForbiddenWords`.
- **Toda regla de pluralización necesita casos negativos.** Es fácil escribir una regla que detecte `casinos` y de paso censure `casas`. Cada añadido debe traer pruebas de lo que **no** debe censurarse.
- **Longitud mínima de 4 antes de quitar la `s`.** Sin ese límite, `dos` → `do` y `mas` → `ma` producen falsos positivos constantes.
- Los `Pluralizer` devuelven **candidatos, no certezas**. Un candidato inventado no hace daño: simplemente no coincide con la lista. Quien llama comprueba.
- **Sin PHPUnit**: la librería se copia dentro de sitios heredados (WoWonder) donde no siempre hay composer, y las pruebas deben poder ejecutarse igual con `php` a secas.
- No introduzcas dependencias de ejecución. Es un requisito, no una preferencia: `chismy.com` la consume sin composer.
- El código y los comentarios van en español, sin tildes en los comentarios de código PHP heredado (los archivos de `chismy.com` no garantizan UTF-8 en todas las rutas).
- Si cambias `mask()`, coordina el cambio con los tres sitios o se verán distintos.

## Validación requerida

Antes de dar por terminado cualquier cambio: `php tests/run.php` en verde y `php -l` limpio en los archivos tocados. Un cambio en las reglas de un idioma exige además casos negativos nuevos.
