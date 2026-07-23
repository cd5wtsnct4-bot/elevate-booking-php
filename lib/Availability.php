<?php
declare(strict_types=1);

require_once __DIR__ . '/MicrosoftGraph.php';

/**
 * Merges manual blocks, bookings, and live Microsoft Graph busy times into
 * a single per-day status for a date range. Statuses:
 *
 *   past            - date already gone
 *   blocked         - manually blocked by admin
 *   booked          - approved booking or a Graph calendar conflict
 *   requested       - someone else's pending request
 *   requested_mine  - the viewer's own pending request
 *   open            - available to book
 */
class Availability
{
    public static function forMonth(int $year, int $month, ?int $viewerUserId): array
    {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $end = $start->modify('last day of this month');
        return self::compute($start, $end, $viewerUserId);
    }

    public static function statusForDate(string $isoDate, ?int $viewerUserId): string
    {
        return self::detailForDate($isoDate, $viewerUserId)['status'];
    }

    /**
     * Like statusForDate(), but also reports whether every connected
     * calendar's live Graph check actually succeeded. Callers that are
     * about to accept a new booking (not just display availability) should
     * check graphOk and refuse to proceed if false — a status of 'open'
     * doesn't mean much if we couldn't actually confirm it against the
     * real calendar.
     */
    public static function detailForDate(string $isoDate, ?int $viewerUserId): array
    {
        $date = new DateTimeImmutable($isoDate);
        $result = self::compute($date, $date, $viewerUserId);
        return [
            'status' => $result['days'][$isoDate] ?? 'open',
            'graphOk' => $result['graphOk'],
        ];
    }

    private static function compute(DateTimeImmutable $start, DateTimeImmutable $end, ?int $viewerUserId): array
    {
        $days = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $days[$d->format('Y-m-d')] = 'open';
        }

        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        foreach ($days as $iso => $_) {
            if ($iso < $today) {
                $days[$iso] = 'past';
            }
        }

        $startIso = $start->format('Y-m-d');
        $endIso = $end->format('Y-m-d');

        // Manual blocks.
        $stmt = db()->prepare(
            'SELECT blocked_date FROM blocked_dates WHERE blocked_date BETWEEN ? AND ?'
        );
        $stmt->execute([$startIso, $endIso]);
        foreach ($stmt->fetchAll() as $row) {
            self::setIfNotPast($days, $row['blocked_date'], 'blocked');
        }

        // Bookings (approved + pending).
        $stmt = db()->prepare(
            'SELECT booking_date, status, user_id FROM bookings
             WHERE booking_date BETWEEN ? AND ? AND status IN (\'approved\', \'pending\')'
        );
        $stmt->execute([$startIso, $endIso]);
        foreach ($stmt->fetchAll() as $row) {
            if ($row['status'] === 'approved') {
                self::setIfNotPast($days, $row['booking_date'], 'booked');
            } elseif ((int) $row['user_id'] === (int) $viewerUserId) {
                self::setIfNotPast($days, $row['booking_date'], 'requested_mine');
            } else {
                self::setIfNotPast($days, $row['booking_date'], 'requested');
            }
        }

        // Live Graph busy dates from every connected calendar.
        $graphOk = true;
        $graphError = null;
        $calendars = db()->query('SELECT * FROM calendar_accounts')->fetchAll();
        foreach ($calendars as $cal) {
            try {
                $busy = MicrosoftGraph::getBusyDates($cal, $start, $end);
                foreach ($busy as $iso) {
                    self::setIfNotPast($days, $iso, 'booked');
                }
            } catch (Throwable $e) {
                $graphOk = false;
                $graphError = $e->getMessage();
                db()->prepare('UPDATE calendar_accounts SET last_sync_error = ? WHERE id = ?')
                    ->execute([substr($e->getMessage(), 0, 500), $cal['id']]);
            }
        }

        return ['days' => $days, 'graphOk' => $graphOk, 'graphError' => $graphError];
    }

    /**
     * Status precedence, highest wins — a later, lower-priority write never
     * downgrades an already-set day (e.g. a "requested" row can't overwrite
     * "booked", and nothing can overwrite "past").
     */
    private const PRECEDENCE = [
        'past' => 5,
        'blocked' => 4,
        'booked' => 4,
        'requested_mine' => 2,
        'requested' => 1,
        'open' => 0,
    ];

    private static function setIfNotPast(array &$days, string $iso, string $status): void
    {
        if (!array_key_exists($iso, $days)) {
            return;
        }
        $current = $days[$iso];
        if (self::PRECEDENCE[$status] >= self::PRECEDENCE[$current]) {
            $days[$iso] = $status;
        }
    }
}
