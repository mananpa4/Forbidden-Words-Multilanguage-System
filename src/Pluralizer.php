<?php

declare(strict_types=1);

namespace ForbiddenWords;

/**
 * Deriva los singulares posibles de una palabra.
 *
 * La lista de palabras prohibidas la escribe una persona y solo declara el
 * singular. En vez de precalcular los plurales de toda la lista (que duplicaria
 * el conjunto en memoria y obligaria a acertar la pluralizacion de cada palabra),
 * se hace el camino inverso: de la palabra que aparece en el texto se derivan sus
 * posibles singulares y se comprueba si alguno esta prohibido.
 *
 * Devuelve candidatos, no certezas: quien llama comprueba cuales existen en la
 * lista. Un candidato inventado no hace dano; simplemente no coincide.
 */
interface Pluralizer
{
    /**
     * Codigo ISO 639-1 del idioma, p. ej. "es".
     */
    public function language(): string;

    /**
     * @return list<string> Posibles singulares de $word, ya en minusculas.
     */
    public function singularCandidates(string $word): array;
}
