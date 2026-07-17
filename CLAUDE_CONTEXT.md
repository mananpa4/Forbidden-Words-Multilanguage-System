# CLAUDE_CONTEXT.md

Memoria viva del proyecto. Se actualiza cuando cambia el estado real, se toma una decisión técnica o aparece un problema. Lo permanente (arquitectura, convenciones, comandos) está en `CLAUDE.md`.

**Última actualización:** 2026-07-16

## Estado actual

Primera versión funcional. La librería está completa y probada de forma aislada, pero **ningún sitio la consume todavía**: los tres siguen con su copia propia. La convergencia es el trabajo pendiente principal.

Origen: el filtro de `chismy.com` detectaba `Casino` pero dejaba pasar `casinos`. La causa era que `Wo_ProfanityFilter()` compara el token con la lista mediante `isset($words[$lower])`, una coincidencia exacta. El arreglo se aplicó en `chismy.com` **y** se extrajo aquí como implementación de referencia.

## Funcionalidades implementadas

- Coincidencia por palabra completa con tokenizado Unicode.
- Detección de plurales por derivación de singulares, en español e inglés.
- Español: `casinos`→`casino`, `papeles`→`papel`, `luces`→`luz`, `lapices`→`lapiz`, más variante acentuada (`aviones`→`avion`/`avión`).
- Inglés: `cats`→`cat`, `boxes`→`box`, `cities`→`city`, `knives`→`knife`/`knif`.
- Capas `base` / `extra` / `removed`.
- Subcadenas CJK (Han, Hiragana, Katakana, Hangul), ordenadas de más larga a más corta.
- Enmascarado parcial compatible con los tres sitios (`pendejo` → `pen****`).
- `WordSet::parse()` para listas separadas por comas y saltos de línea.
- 30 pruebas en verde, incluidos casos negativos y CJK.

## En progreso

Nada activo.

## Tareas pendientes

1. **Hacer que `chismy.com` consuma la librería.** Hoy tiene su propia copia corregida en `assets/includes/functions_profanity.php`. Es un WoWonder sin composer, así que hay que decidir entre incluir `src/` por `require` o publicar el paquete.
2. **Migrar `dowdes.com` y `shomex.com.mx`.** Ambos son Laravel 8.2 con `App\Services\ProfanityFilter`; ahí sí hay composer y la migración es limpia.
3. **Portugués.** El ecosistema apunta a Brasil (`BRL` ya está contemplado en Chismy Messenger). Reglas: `-ão`→`-ões`/`-ãos`, `-l`→`-is` (`papel`→`papéis`), `-m`→`-ns` (`homem`→`homens`).
4. **Decidir el destino de `mask()`.** Enmascarar deja adivinar la palabra. Si algún día se quiere sustitución total, hay que coordinarlo con los tres sitios a la vez.

## Decisiones técnicas tomadas

- **Derivar singulares en vez de pluralizar la lista.** Precalcular plurales duplicaría el conjunto y obligaría a acertar la pluralización de cada entrada escrita a mano. Derivar del texto es O(1) por token y no toca la lista.
- **Longitud mínima 4 para quitar la `s`.** Con menos, `dos`→`do` y `mas`→`ma` disparan falsos positivos. Es un límite empírico, no teórico.
- **Los `Pluralizer` devuelven candidatos, no certezas.** Simplifica cada idioma: puede proponer de más sin romper nada, porque la lista es la autoridad.
- **Sin PHPUnit ni dependencias de ejecución.** `chismy.com` es un WoWonder sin composer; si la librería dependiera de algo, no podría consumirla.
- **CJK por subcadena.** Excepción consciente a la regla de palabra completa: sin espacios, la coincidencia por palabra nunca dispararía. Heredado de `shomex.com.mx`, que ya lo resolvía así.
- **`mask()` con reparto mitad/mitad congelado.** Los tres sitios ya censuran así; cambiarlo los desincronizaría visualmente.

## Problemas conocidos

- **Tildes en plurales del español.** El plural desplaza la tilde (`avión`→`aviones`), así que de `aviones` se deriva `avion` sin tilde. Se emite también una variante acentuada en la última vocal, que cubre el caso común pero **no todos**. Si la lista trae una forma acentuada irregular, hay que añadirla a mano.
- **Plurales irregulares no se derivan** (`children`, `people`, `mice`, `regímenes`). No siguen regla; si hacen falta, se añaden como entradas propias.
- **Riesgo residual de falso positivo.** Si un plural legítimo deriva en una palabra prohibida, se censura. El límite de longitud 4 mitiga los casos frecuentes, pero no es demostrable que no queden.
- **No hay normalización de evasiones** (`c4sino`, `c-a-s-i-n-o`, homoglifos cirílicos). La librería no intenta detectarlas hoy.
- **La librería no está publicada** en Packagist ni referenciada por ningún sitio.

## Próximos pasos recomendados

1. Migrar primero `shomex.com.mx`: es Laravel, tiene composer y su `ProfanityFilter` ya es el más cercano a esta implementación (de ahí salió el CJK).
2. Comparar las tres copias antes de borrarlas — puede haber reglas útiles que no llegaron aquí.
3. Añadir el portugués con sus casos negativos.
4. Evaluar la normalización de evasiones solo si la moderación real la pide: es la clase de función que genera falsos positivos a mansalva.
