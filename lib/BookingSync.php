<?php
declare(strict_types=1);

require_once __DIR__ . '/MicrosoftGraph.php';

/**
 * Centralizes booking-status transitions and their corresponding Microsoft
 * Graph calendar event create/delete calls, so admin/bookings.php (quick
 * actions) and admin/booking-form.php (full edit) share one code path.
 *
 * Every public method returns a ?string warning message on Graph failure —
 * the local database change always still happens, since a calendar-sync
 * hiccup shouldn't block the admin from managing bookings.
 */
class BookingSync
{
    public static function approve(array $booking, int $calendarAccountId, int $adminId): ?string
    {
        $warning = null;
        $eventId = null;

        $calStmt = db()->prepare('SELECT * FROM calendar_accounts WHERE id = ?');
        $calStmt->execute([$calendarAccountId]);
        $calendar = $calStmt->fetch();

        if ($calendar) {
            try {
                $label = $booking['type'] === 'training' ? 'Training Session' : 'Meeting';
                $eventId = MicrosoftGraph::createAllDayEvent(
                    $calendar,
                    $booking['booking_date'],
                    $label . ': ' . $booking['title'],
                    $booking['notes'] ?? ''
                );
            } catch (Throwable $e) {
                $warning = 'Approved locally, but the calendar event could not be created: ' . $e->getMessage();
            }
        } else {
            $warning = 'Approved locally, but no calendar was selected/connected — no event was created.';
        }

        db()->prepare(
            'UPDATE bookings SET status = \'approved\', calendar_account_id = ?, ms_event_id = ?, decided_by = ?, decided_at = NOW() WHERE id = ?'
        )->execute([$calendar ? $calendarAccountId : null, $eventId, $adminId, $booking['id']]);

        return $warning;
    }

    public static function decline(array $booking, int $adminId, ?string $note = null): ?string
    {
        $warning = self::removeEventIfAny($booking);

        db()->prepare(
            'UPDATE bookings SET status = \'declined\', ms_event_id = NULL, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?'
        )->execute([$adminId, $note, $booking['id']]);

        return $warning;
    }

    public static function cancel(array $booking, int $adminId): ?string
    {
        $warning = self::removeEventIfAny($booking);

        db()->prepare(
            'UPDATE bookings SET status = \'cancelled\', ms_event_id = NULL, decided_by = ?, decided_at = NOW() WHERE id = ?'
        )->execute([$adminId, $booking['id']]);

        return $warning;
    }

    public static function delete(array $booking): ?string
    {
        $warning = self::removeEventIfAny($booking);
        db()->prepare('DELETE FROM bookings WHERE id = ?')->execute([$booking['id']]);
        return $warning;
    }

    private static function removeEventIfAny(array $booking): ?string
    {
        if (!$booking['ms_event_id'] || !$booking['calendar_account_id']) {
            return null;
        }
        $calStmt = db()->prepare('SELECT * FROM calendar_accounts WHERE id = ?');
        $calStmt->execute([$booking['calendar_account_id']]);
        $calendar = $calStmt->fetch();
        if (!$calendar) {
            return null;
        }
        try {
            MicrosoftGraph::deleteEvent($calendar, $booking['ms_event_id']);
        } catch (Throwable $e) {
            return 'The calendar event could not be removed automatically: ' . $e->getMessage();
        }
        return null;
    }
}
