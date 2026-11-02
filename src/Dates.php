<?php
declare(strict_types=1);

namespace Corder;

use DateTimeImmutable;

/** Conversion entre les DATETIME de la base (heure belge) et le format ISO 8601 de l'API. */
final class Dates
{
    /** « 2026-11-05 09:00:00 » → « 2026-11-05T09:00:00+01:00 » */
    public static function iso(?string $dbValue): ?string
    {
        return $dbValue === null ? null : (new DateTimeImmutable($dbValue))->format(DATE_ATOM);
    }

    /** « 2026-11-05T09:00:00+01:00 » (n'importe quel fuseau) → « 2026-11-05 09:00:00 » en heure belge */
    public static function toDb(string $iso): string
    {
        return (new DateTimeImmutable($iso))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
