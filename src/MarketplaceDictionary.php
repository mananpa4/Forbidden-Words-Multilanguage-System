<?php

declare(strict_types=1);

namespace ForbiddenWords;

/**
 * Diccionario de comunicacion prohibida para marketplaces.
 *
 * Carga dictionaries/marketplace-es.json y expone:
 *  - scan(): evaluacion de un mensaje con nivel de riesgo, acciones y flags,
 *    alineada al contrato de salida del sistema Trust & Safety de SHOMEX.
 *  - wordFilter(): un ForbiddenWords solo con las palabras "block" del
 *    diccionario, para censurado visual.
 *
 * El diccionario NO decide politicas: clasifica senales. La decision final
 * (ALLOW / BLOCK / REVIEW) y la sancion son del backend que llama.
 *
 * Filosofia de severidad:
 *  - "signal"  → sola no infringe (mencionar WhatsApp para comparar es legitimo).
 *                Suma puntos y puede escalar si se combina con otras senales.
 *  - "block"   → la coincidencia de la frase/word ya es infraccion clara.
 *
 * Un mensaje puede acumular senales: la suma decide LOW/MEDIUM/HIGH siguiendo
 * la matriz del prompt Trust & Safety (0-1 LOW, 2-4 MEDIUM, >=5 HIGH), con
 * bonus por evasiones deliberadas y minimo un hit block → HIGH.
 */
final class MarketplaceDictionary
{
    public const RISK_LOW = 'LOW';
    public const RISK_MEDIUM = 'MEDIUM';
    public const RISK_HIGH = 'HIGH';

    public const ACTION_ALLOW = 'ALLOW';
    public const ACTION_ALLOW_WITH_WARNING = 'ALLOW_WITH_WARNING';
    public const ACTION_REVIEW = 'REVIEW';
    public const ACTION_BLOCK = 'BLOCK';

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    private ?ForbiddenWords $wordFilter = null;

    private function __construct(
        private readonly string $path,
    ) {
    }

    public static function fromFile(?string $path = null): self
    {
        return new self($path ?? dirname(__DIR__).'/dictionaries/marketplace-es.json');
    }

    /**
     * Evaluacion completa de un mensaje.
     *
     * @return array{
     *     risk_level: string,
     *     action: string,
     *     score: int,
     *     matched_categories: list<string>,
     *     flags: list<array<string, mixed>>,
     *     obfuscation_detected: bool,
     *     evasion_techniques: list<string>,
     *     escalate_to_human: bool,
     *     user_message: string,
     *     censored: string,
     *     policy_version: string
     * }
     */
    public function scan(?string $message, array $context = []): array
    {
        $data = $this->load();
        $message = (string) $message;

        $normalized = EvasionNormalizer::normalizeText($message);
        $evasion = EvasionNormalizer::detectEvasion($message);

        $score = 0;
        $categories = [];
        $flags = [];
        $blockHit = false;

        foreach ($data['categories'] as $categoryId => $cat) {
            $catScore = (int) ($cat['score'] ?? 1);
            $defaultSeverity = (string) ($cat['severity_default'] ?? 'signal');
            $matchedInCategory = [];

            // Palabras: comparar tanto el token original como el normalizado.
            foreach (($cat['words'] ?? []) as $word) {
                $word = mb_strtolower(trim((string) $word));
                if ($word === '') {
                    continue;
                }
                if ($this->tokenMatches($normalized, $word) || $this->tokenMatches($message, $word)) {
                    $matchedInCategory[] = $word;
                    $severity = $defaultSeverity;
                    if ($severity === 'block') {
                        $blockHit = true;
                    }
                    $flags[] = $this->flag($categoryId, $word, $severity, 'WORD_MATCH', $catScore);
                }
            }

            // Frases: busqueda de subcadena en el texto normalizado (ya sin
            // espacios intra-palabra), ademas del original con espacios.
            foreach (($cat['phrases'] ?? []) as $phrase) {
                $phrase = mb_strtolower(trim((string) $phrase));
                if ($phrase === '') {
                    continue;
                }
                $phraseNorm = EvasionNormalizer::normalizeText($phrase);
                if (
                    mb_strpos($normalized, $phraseNorm) !== false
                    || mb_strpos(mb_strtolower($message), $phrase) !== false
                ) {
                    $matchedInCategory[] = $phrase;
                    $severity = $defaultSeverity;
                    // Frases de categorias block siempre elevan.
                    if ($severity === 'block') {
                        $blockHit = true;
                    }
                    $flags[] = $this->flag($categoryId, $phrase, $severity, 'PHRASE_MATCH', $catScore);
                }
            }

            if ($matchedInCategory !== []) {
                $categories[] = $categoryId;
                // Suma una vez por categoria, no por cada coincidencia repetida.
                $score += $catScore;
            }
        }

        // Bonus por evasion deliberada.
        if ($evasion !== []) {
            $score += 2;
            foreach ($evasion as $tech) {
                $flags[] = $this->flag('EVASION_BYPASS', $tech, 'signal', 'OBFUSCATION', 2);
            }
        }

        // Credenciales (C7): si se combina con solicitud explicita → HIGH fijo.
        $credentialHit = in_array('SENSITIVE_DATA', $categories, true);
        $offPlatformPay = in_array('OFF_PLATFORM_PAYMENT', $categories, true);
        $contactish = in_array('EXTERNAL_MESSAGING', $categories, true)
            || in_array('CONTACT_SHARE', $categories, true);

        // Regla de combinacion critica (seccion 6.1 del prompt):
        // contacto + desvio transaccional → HIGH.
        if ($offPlatformPay && ($contactish || in_array('EXTERNAL_URL', $categories, true))) {
            $score += 5;
        }
        if ($credentialHit) {
            $score = max($score, 6);
        }
        if ($blockHit) {
            $score = max($score, 5);
        }

        [$risk, $action] = $this->decide($score, $blockHit, $credentialHit);

        return [
            'risk_level' => $risk,
            'action' => $action,
            'score' => $score,
            'matched_categories' => $categories,
            'flags' => $flags,
            'obfuscation_detected' => $evasion !== [],
            'evasion_techniques' => $evasion,
            'escalate_to_human' => $risk === self::RISK_HIGH,
            'user_message' => $this->userMessage($action),
            'censored' => $this->wordFilter()->censor($message),
            'policy_version' => (string) ($data['version'] ?? '0'),
        ];
    }

    /** ForbiddenWords solo con palabras de categorias "block" (para censura). */
    public function wordFilter(): ForbiddenWords
    {
        if ($this->wordFilter !== null) {
            return $this->wordFilter;
        }

        $data = $this->load();
        $blockWords = [];
        foreach ($data['categories'] as $cat) {
            if (($cat['severity_default'] ?? '') === 'block') {
                foreach (($cat['words'] ?? []) as $w) {
                    $w = mb_strtolower(trim((string) $w));
                    if ($w !== '') {
                        $blockWords[] = $w;
                    }
                }
            }
        }

        return $this->wordFilter = new ForbiddenWords(new WordSet(base: $blockWords));
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if (! is_file($this->path)) {
            throw new \RuntimeException("Diccionario no encontrado: {$this->path}");
        }

        $json = file_get_contents($this->path);
        $data = json_decode((string) $json, true);
        if (! is_array($data) || ! isset($data['categories'])) {
            throw new \RuntimeException("Diccionario invalido: {$this->path}");
        }

        return $this->data = $data;
    }

    private function tokenMatches(string $text, string $word): bool
    {
        // Palabra completa sobre el texto ya normalizado.
        return (bool) preg_match(
            '/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/ui',
            $text,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function decide(int $score, bool $blockHit, bool $credentialHit): array
    {
        if ($blockHit || $credentialHit || $score >= 5) {
            return [self::RISK_HIGH, self::ACTION_BLOCK];
        }
        if ($score >= 2) {
            return [self::RISK_MEDIUM, self::ACTION_ALLOW_WITH_WARNING];
        }

        return [self::RISK_LOW, self::ACTION_ALLOW];
    }

    private function userMessage(string $action): string
    {
        return match ($action) {
            self::ACTION_BLOCK => 'Comunicacion no permitida. El contenido puede comprometer la seguridad o infringir las politicas de la plataforma. Modifica el mensaje para continuar.',
            self::ACTION_ALLOW_WITH_WARNING => 'Advertencia: este mensaje podria infringir nuestras politicas. Manten la comunicacion y las operaciones dentro de los canales autorizados.',
            default => '',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function flag(
        string $category,
        string $matched,
        string $severity,
        string $evidence,
        int $score,
    ): array {
        return [
            'category' => $category,
            'matched_pattern' => $matched,
            'risk_assigned' => $severity === 'block' ? self::RISK_HIGH : self::RISK_MEDIUM,
            'evidence_type' => $evidence,
            'recommended_action' => $severity === 'block' ? self::ACTION_BLOCK : self::ACTION_REVIEW,
            'score' => $score,
            'redacted' => false,
        ];
    }
}
