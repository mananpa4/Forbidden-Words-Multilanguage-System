<?php

declare(strict_types=1);

namespace ForbiddenWords;

/**
 * Lista efectiva de palabras prohibidas, en tres capas.
 *
 *   base     lista heredada del sitio (en Chismy viene de `Wo_Config`, que es un
 *            VARCHAR(20000) y se trunca en silencio al pasarse).
 *   extra    anadidos sin limite, gestionados por administracion.
 *   removed  exclusiones: permite desactivar una palabra de `base` sin editar la
 *            configuracion heredada, que a menudo no es editable con seguridad.
 *
 * El resultado es un mapa palabra => true en minusculas, para que comprobar sea
 * O(1) sin recorrer la lista por cada token del texto.
 */
final class WordSet
{
    /** @var array<string,bool>|null */
    private ?array $set = null;

    /** @var list<string>|null */
    private ?array $cjk = null;

    /**
     * @param list<string> $base
     * @param list<string> $extra
     * @param list<string> $removed
     */
    public function __construct(
        private array $base = [],
        private array $extra = [],
        private array $removed = [],
    ) {
    }

    /**
     * @return array<string,bool>
     */
    public function all(): array
    {
        if ($this->set !== null) {
            return $this->set;
        }

        $removed = [];
        foreach ($this->removed as $word) {
            $lower = mb_strtolower(trim($word));
            if ($lower !== '') {
                $removed[$lower] = true;
            }
        }

        $set = [];
        foreach ([...$this->base, ...$this->extra] as $word) {
            $lower = mb_strtolower(trim($word));
            if ($lower === '' || isset($removed[$lower])) {
                continue;
            }
            $set[$lower] = true;
        }

        return $this->set = $set;
    }

    public function has(string $lowerWord): bool
    {
        return isset($this->all()[$lowerWord]);
    }

    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Palabras escritas en Han, Hiragana, Katakana o Hangul, de mas larga a mas
     * corta.
     *
     * El chino, el japones y el coreano no separan palabras con espacios, asi
     * que la coincidencia por palabra completa nunca dispara: hay que buscar
     * subcadenas. Se ordenan de mas larga a mas corta para que una palabra
     * contenida en otra no la parta antes de tiempo.
     *
     * @return list<string>
     */
    public function cjkWords(): array
    {
        if ($this->cjk !== null) {
            return $this->cjk;
        }

        $cjk = array_filter(
            array_keys($this->all()),
            static fn (string $word): bool => (bool) preg_match(
                '/^[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+$/u',
                $word,
            ),
        );
        usort($cjk, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $this->cjk = array_values($cjk);
    }

    /**
     * Divide texto separado por comas y/o saltos de linea, como lo escribe un
     * administrador en un textarea.
     *
     * @return list<string>
     */
    public static function parse(string $text): array
    {
        $words = preg_split('/[,\r\n]+/u', $text) ?: [];
        $clean = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ($word !== '') {
                $clean[] = $word;
            }
        }

        return array_values(array_unique($clean));
    }
}
