# CLAUDE_CONTEXT.md

Memoria viva del proyecto. Se actualiza cuando cambia el estado real, se toma una decisión técnica o aparece un problema. Lo permanente (arquitectura, convenciones, comandos) está en `CLAUDE.md`.

**Última actualización:** 2026-10-11

## Estado actual

Fase marketplace. La librería ya no es solo censura de groserías: incluye detección de comunicacion prohibida en marketplaces (contacto externo, pagos fuera de plataforma, manipulación de disputas, phishing, etc.) con clasificación de riesgo LOW/MEDIUM/HIGH y acciones ALLOW/ALLOW_WITH_WARNING/REVIEW/BLOCK alineadas al sistema Trust & Safety de SHOMEX. **Ningún sitio consume todavía la librería completa**: los tres siguen con su copia propia.

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
- **`EvasionNormalizer`** — normalización anti-evasión: leet (4=a, 3=e...), homoglifos cirílicos/griegos, caracteres de ancho cero, separadores intra-palabra (`w.h.a.t.s`), letras espaciadas (`p a g a   p o r   f u e r a`), variantes fonéticas (guasap/wasap→whatsapp). `detectEvasion()` reporta técnicas para `EVASION_BYPASS`.
- **`MarketplaceDictionary`** — carga `dictionaries/marketplace-es.json` (C1-C12: mensajería externa, pagos fuera de plataforma, logística, disputas, datos sensibles, insultos, amenazas, reputación, phishing, desviación a competidores, contacto). `scan()` devuelve riesgo, acción, score, flags y censurado. Regla de combinación: contacto + pago externo → HIGH. Señales solas → LOW/MEDIUM.
- 52 pruebas en verde, incluidos casos negativos, CJK, evasión y marketplace.

## En progreso

Nada activo.

## Tareas pendientes

1. **Hacer que `shomex.com.mx` consuma `MarketplaceDictionary::scan()` en chat/Q&A/reseñas** (ya identificados los puntos: MessageController, ConversationController, API chat, ProductQuery, Review, Feed).
2. **Portar `EvasionNormalizer` + `MarketplaceDictionary` a NSFWJS-Detector** (packages/api/src/text/) para OCR de imágenes.
3. **Hacer que `chismy.com` consuma la librería** (require directo, sin composer).
4. **Portugués.** Reglas: `-ão`→`-ões`/`-ãos`, `-l`→`-is`, `-m`→`-ns`.
5. **Ampliar `marketplace-es.json`** con más variantes regionales (centroamérica, Colombia, Argentina) tras calibrar con mensajes reales anonimizados.

## Decisiones técnicas tomadas

- **Derivar singulares en vez de pluralizar la lista.** Precalcular plurales duplicaría el conjunto y obligaría a acertar la pluralización de cada entrada escrita a mano. Derivar del texto es O(1) por token y no toca la lista.
- **Longitud mínima 4 para quitar la `s`.** Con menos, `dos`→`do` y `mas`→`ma` disparan falsos positivos. Es un límite empírico, no teórico.
- **Los `Pluralizer` devuelven candidatos, no certezas.** Simplifica cada idioma: puede proponer de más sin romper nada, porque la lista es la autoridad.
- **Sin PHPUnit ni dependencias de ejecución.** `chismy.com` es un WoWonder sin composer; si la librería dependiera de algo, no podría consumirla.
- **CJK por subcadena.** Excepción consciente a la regla de palabra completa: sin espacios, la coincidencia por palabra nunca dispararía. Heredado de `shomex.com.mx`, que ya lo resolvía así.
- **`mask()` con reparto mitad/mitad congelado.** Los tres sitios ya censuran así; cambiarlo los desincronizaría visualmente.
- **Diccionario marketplace con severidad `signal` vs `block`.** Mencionar WhatsApp para comparar es legítimo; una frase de "paga por fuera" es infracción clara. La suma de señales decide el riesgo, no cada palabra aisladamente.
- **`EvasionNormalizer` trabaja sobre copia normalizada, nunca borra el original.** El texto original se conserva para auditoría; la normalización es representación auxiliar para búsqueda de patrones.
- **Colapsar solo letras sueltas espaciadas, no todos los espacios.** `preg_replace` agresivo de espacios fusiona palabras legítimas y destruye números de pedido; el regex exige grupos de `\p{L} ` unitarios con delimitadores.

## Problemas conocidos

- **Tildes en plurales del español.** El plural desplaza la tilde (`avión`→`aviones`), así que de `aviones` se deriva `avion` sin tilde. Se emite también una variante acentuada en la última vocal, que cubre el caso común pero **no todos**. Si la lista trae una forma acentuada irregular, hay que añadirla a mano.
- **Plurales irregulares no se derivan** (`children`, `people`, `mice`, `regímenes`). No siguen regla; si hacen falta, se añaden como entradas propias.
- **Riesgo residual de falso positivo.** Si un plural legítimo deriva en una palabra prohibida, se censura. El límite de longitud 4 mitiga los casos frecuentes, pero no es demostrable que no queden.
- **`detectEvasion` es heurístico.** `LEET` se activa con dígitos pegados a letras; puede dar falsos positivos en textos con precios (`$50` no es evasión). mitigable con contexto.
- **Frases marketplace no cubren todas las variantes.** "No abras el reclamo" vs "No abras un reclamo" requiere enumerar variantes; un matcher difuso futuro podría reducir la lista.
- **La librería no está publicada** en Packagist ni referenciada por ningún sitio.

## Próximos pasos recomendados

1. Integrar `MarketplaceDictionary` en `shomex.com.mx` (chat + Q&A + reseñas) — el sistema Trust & Safety del prompt lo exige.
2. Portar `EvasionNormalizer` + `MarketplaceDictionary` al paquete TS de NSFWJS-Detector para OCR.
3. Comparar las tres copias antes de borrarlas — puede haber reglas útiles que no llegaron aquí.
4. Añadir el portugués con sus casos negativos.

