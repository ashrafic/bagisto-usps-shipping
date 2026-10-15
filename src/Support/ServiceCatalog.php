<?php

namespace Ashrafic\UspsShipping\Support;

class ServiceCatalog
{
    /**
     * Domestic mail classes offered at checkout, keyed by USPS API mailClass code.
     *
     * @var array<string, string>
     */
    protected array $domestic = [
        'USPS_GROUND_ADVANTAGE' => 'USPS Ground Advantage',
        'PRIORITY_MAIL' => 'Priority Mail',
        'PRIORITY_MAIL_EXPRESS' => 'Priority Mail Express',
        'MEDIA_MAIL' => 'Media Mail',
        'LIBRARY_MAIL' => 'Library Mail',
    ];

    /**
     * International mail classes offered at checkout.
     *
     * @var array<string, string>
     */
    protected array $international = [
        'PRIORITY_MAIL_INTERNATIONAL' => 'Priority Mail International',
        'PRIORITY_MAIL_EXPRESS_INTERNATIONAL' => 'Priority Mail Express International',
        'FIRST_CLASS_PACKAGE_INTERNATIONAL' => 'First-Class Package International',
    ];

    /**
     * Mail classes quoted when the admin leaves Allowed Methods empty.
     *
     * @return string[]
     */
    public function defaultClasses(): array
    {
        return ['USPS_GROUND_ADVANTAGE', 'PRIORITY_MAIL'];
    }

    /**
     * All domestic mail class codes with their customer-facing labels.
     *
     * @return array<string, string>
     */
    public function domesticClasses(): array
    {
        return $this->domestic;
    }

    /**
     * All international mail class codes with their customer-facing labels.
     *
     * @return array<string, string>
     */
    public function internationalClasses(): array
    {
        return $this->international;
    }

    /**
     * Every supported mail class code with its label, domestic first.
     *
     * @return array<string, string>
     */
    public function allClasses(): array
    {
        return $this->domestic + $this->international;
    }

    /**
     * Normalise a configured value into a known mail class code.
     */
    public function normalizeClass(mixed $value): ?string
    {
        $code = strtoupper(trim((string) $value));

        $code = preg_replace('/[^A-Z0-9]+/', '_', $code) ?? '';
        $code = trim($code, '_');

        return isset($this->allClasses()[$code]) ? $code : null;
    }

    /**
     * Resolve the configured allowed classes, falling back to the defaults.
     *
     * @return string[]
     */
    public function allowedClasses(mixed $configured): array
    {
        $raw = is_string($configured) ? explode(',', $configured) : (is_array($configured) ? $configured : []);

        $classes = [];

        foreach ($raw as $value) {
            $code = $this->normalizeClass($value);

            if ($code !== null) {
                $classes[] = $code;
            }
        }

        $classes = array_values(array_unique($classes));

        return $classes !== [] ? $classes : $this->defaultClasses();
    }

    /**
     * Label for a mail class code, or the code itself when unknown.
     */
    public function label(string $code): string
    {
        return $this->allClasses()[$code] ?? $code;
    }

    /**
     * Whether the class belongs to the international price table.
     */
    public function isInternationalClass(string $code): bool
    {
        return isset($this->international[$code]);
    }
}
