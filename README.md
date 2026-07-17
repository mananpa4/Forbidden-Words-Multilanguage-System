# Forbidden Words — Multilanguage System

Filtro de palabras prohibidas para PHP 8.2+, pensado para español, inglés y CJK. Sin dependencias.

Detecta **el plural aunque la lista solo declare el singular**: con `casino` en la lista, `casinos` también se censura.

```php
use ForbiddenWords\ForbiddenWords;
use ForbiddenWords\WordSet;

$filter = new ForbiddenWords(new WordSet(base: ['casino', 'apuesta']));

$filter->censor('Vamos a los casinos de apuestas');
// "Vamos a los cas**** de apue****"

$filter->containsForbidden('todo en orden');   // false
$filter->isForbidden('CASINOS');               // true
```

## Por qué existe

La misma lógica estaba copiada en tres sitios (`chismy.com`, `dowdes.com`, `shomex.com.mx`) y las copias divergieron. Esta es la implementación de referencia hacia la que deben converger.

## Qué hace distinto

**Palabra completa, no subcadena.** El enfoque ingenuo con `str_replace` destroza palabras legítimas: con `as` prohibido, `clase` acaba como `cl**e`. Aquí se tokeniza y se compara token a token.

**Deriva el singular, no pluraliza la lista.** La lista la escribe una persona y solo declara el singular. En vez de precalcular los plurales de todas las entradas —duplicando el conjunto y teniendo que acertar cada pluralización— se hace el camino inverso: de la palabra del texto se derivan sus posibles singulares.

| Idioma | Ejemplos |
|---|---|
| Español | `casinos`→`casino`, `papeles`→`papel`, `luces`→`luz`, `lapices`→`lapiz` |
| Inglés | `cats`→`cat`, `boxes`→`box`, `cities`→`city`, `knives`→`knife` |

**CJK por subcadena.** El chino, el japonés y el coreano no separan palabras con espacios, así que para ellos la coincidencia por palabra nunca dispararía. Es la excepción deliberada.

**No censura de más.** `casas`, `flores`, `clase` y `dos` quedan intactas. Cada regla trae casos negativos en las pruebas.

## Tres capas de lista

```php
new WordSet(
    base:    ['casino', 'apuesta'],  // lista heredada del sitio
    extra:   ['nueva-palabra'],      // añadidos sin límite
    removed: ['apuesta'],            // exclusiones sin tocar `base`
);
```

`removed` existe porque en WoWonder la lista base vive en un `VARCHAR(20000)` que no siempre se puede editar con seguridad.

## Añadir un idioma

Implementa `Pluralizer` y pásalo al constructor:

```php
final class PortuguesePluralizer implements Pluralizer
{
    public function language(): string { return 'pt'; }

    public function singularCandidates(string $word): array
    {
        // "papéis" -> "papel", "homens" -> "homem"
        return [/* ... */];
    }
}

$filter = new ForbiddenWords($words, [new PortuguesePluralizer()]);
```

Devuelve **candidatos, no certezas**: proponer de más no rompe nada, porque la lista es la autoridad. Acompaña siempre la regla con casos negativos.

## Enmascarado

`mask()` deja visible la primera mitad y tapa el resto: `pendejo` → `pen****`. El reparto es intencionadamente idéntico al de los tres sitios; cambiarlo los desincroniza.

## Instalación

```bash
composer require satella/forbidden-words
```

O, en un sitio sin composer (WoWonder), incluyendo `src/` directamente.

## Pruebas

```bash
php tests/run.php
```

Sin PHPUnit a propósito: la librería se copia dentro de sitios heredados donde no siempre hay composer, y las pruebas deben poder ejecutarse igual.

## Alcance

La librería **no decide políticas**. Enmascara texto o responde si contiene algo prohibido. Bloquear, avisar o registrar es de quien la usa.

No intenta detectar evasiones (`c4sino`, `c-a-s-i-n-o`, homoglifos). Ver `CLAUDE_CONTEXT.md` para las limitaciones conocidas.

## Documentación

- `CLAUDE.md` — arquitectura, convenciones y decisiones que no se revierten sin leerlas.
- `CLAUDE_CONTEXT.md` — estado actual, pendientes y problemas conocidos.

## Licencia

MIT.
