<?php
declare(strict_types=1);

namespace Corder;

use Corder\Http\HttpException;

/**
 * Validation des formulaires envoyés par le frontend. Chaque règle note une erreur par champ ;
 * validate() lève une 422 avec { fields: { champ: message } } s'il y en a.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    /** Texte obligatoire, espaces retirés */
    public function required(string $field, int $max = 255): string
    {
        $value = $this->string($field);
        if ($value === '') {
            $this->errors[$field] ??= 'Ce champ est obligatoire.';
        } elseif (mb_strlen($value) > $max) {
            $this->errors[$field] ??= "{$max} caractères maximum.";
        }
        return $value;
    }

    /** Texte facultatif : null s'il est vide */
    public function optional(string $field, int $max = 255): ?string
    {
        $value = $this->string($field);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            $this->errors[$field] ??= "{$max} caractères maximum.";
        }
        return $value;
    }

    public function email(string $field): string
    {
        $value = mb_strtolower($this->required($field, 190));
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] ??= 'Adresse e-mail invalide.';
        }
        return $value;
    }

    public function phone(string $field, bool $required): ?string
    {
        $value = $required ? $this->required($field, 30) : $this->optional($field, 30);
        if ($value !== null && $value !== '' && !preg_match('/^\+?[0-9 .\/()-]{6,30}$/', $value)) {
            $this->errors[$field] ??= 'Numéro de téléphone invalide.';
        }
        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    public function in(string $field, array $allowed, bool $nullable = false): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value === null && $nullable) {
            return null;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->errors[$field] ??= 'Valeur non reconnue.';
            return null;
        }
        return $value;
    }

    /** Case de consentement RGPD : doit valoir exactement true */
    public function consent(string $field = 'consent'): void
    {
        if (($this->data[$field] ?? null) !== true) {
            $this->errors[$field] ??= 'Votre accord est nécessaire.';
        }
    }

    /** Erreur d'une règle propre au contrôleur (unicité, existence en base…) */
    public function error(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    public function validate(): void
    {
        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }
    }

    private function string(string $field): string
    {
        $value = $this->data[$field] ?? '';
        if (!is_string($value)) {
            $this->errors[$field] ??= 'Valeur invalide.';
            return '';
        }
        // Retire les caractères de contrôle (sauf retours à la ligne) et les espaces en trop
        return trim((string) preg_replace('/[^\P{C}\n\t]/u', '', $value));
    }
}
