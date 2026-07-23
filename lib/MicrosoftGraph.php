<?php
declare(strict_types=1);

/**
 * Minimal Microsoft Graph client using plain cURL — no SDK/Composer
 * dependency, so this deploys by copying files to shared hosting with no
 * shell access.
 *
 * Every calendar (including the info@ shared mailbox) is addressed via
 * `/users/{mailbox_email}/...` rather than `/me/...`. That works whether the
 * mailbox belongs to the signed-in account or is a shared mailbox the
 * signed-in account has been granted Full Access delegate permission on in
 * Exchange — one code path either way.
 */
class MicrosoftGraph
{
    private const AUTHORITY = 'https://login.microsoftonline.com';
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';
    private const SCOPES = 'offline_access Calendars.ReadWrite User.Read';

    /**
     * Two distinct redirect URIs, both registered on the Azure App
     * Registration (see DEPLOY.md):
     *   - calendarConnectRedirectUri() — connecting a calendar from an
     *     already-logged-in admin session (admin/calendar-settings.php).
     *   - loginRedirectUri() — "Sign in with Microsoft" on the login page
     *     itself, handled by auth/microsoft-callback.php, which runs before
     *     any local session exists.
     */
    public static function calendarConnectRedirectUri(): string
    {
        return APP_BASE_URL . '/admin/calendar-settings.php?action=callback';
    }

    public static function loginRedirectUri(): string
    {
        return APP_BASE_URL . '/auth/microsoft-callback.php';
    }

    public static function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        $params = [
            'client_id' => MS_CLIENT_ID,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => self::SCOPES,
            'state' => $state,
            // Always show the account picker so the admin can choose a
            // different Microsoft account rather than being kept signed in
            // as whoever last authenticated.
            'prompt' => 'select_account',
        ];
        return self::AUTHORITY . '/' . MS_TENANT_ID . '/oauth2/v2.0/authorize?' . http_build_query($params);
    }

    public static function exchangeCodeForToken(string $code, string $redirectUri): array
    {
        return self::postToken([
            'client_id' => MS_CLIENT_ID,
            'client_secret' => MS_CLIENT_SECRET,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPES,
        ]);
    }

    public static function refreshAccessToken(string $refreshToken): array
    {
        return self::postToken([
            'client_id' => MS_CLIENT_ID,
            'client_secret' => MS_CLIENT_SECRET,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => self::SCOPES,
        ]);
    }

    private static function postToken(array $params): array
    {
        $url = self::AUTHORITY . '/' . MS_TENANT_ID . '/oauth2/v2.0/token';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode((string) $response, true) ?? [];
        if ($status >= 400) {
            throw new RuntimeException('Microsoft token endpoint error: ' . ($json['error_description'] ?? $response));
        }
        return $json;
    }

    /**
     * Fetches the signed-in user's own profile (used right after connecting
     * to determine the mailbox address, unless a shared-mailbox address was
     * explicitly supplied instead).
     */
    public static function getMe(string $accessToken): array
    {
        [$status, $json] = self::request('GET', '/me', $accessToken);
        if ($status >= 400) {
            throw new RuntimeException('Could not read Microsoft profile: ' . json_encode($json));
        }
        return $json;
    }

    /**
     * Ensures the stored access token for a calendar account is still valid,
     * refreshing it (and persisting the new tokens) if it has expired or is
     * about to. Returns a usable plaintext access token.
     */
    public static function ensureValidAccessToken(array $calendarAccount): string
    {
        $expiresAt = $calendarAccount['token_expires_at'] ? strtotime($calendarAccount['token_expires_at']) : 0;
        $accessToken = decryptSecret($calendarAccount['access_token_enc']);

        if ($accessToken !== null && $expiresAt > time() + 60) {
            return $accessToken;
        }

        $refreshToken = decryptSecret($calendarAccount['refresh_token_enc']);
        if (!$refreshToken) {
            throw new RuntimeException('No refresh token stored for this calendar; reconnect it.');
        }

        $tokens = self::refreshAccessToken($refreshToken);
        $newExpiresAt = date('Y-m-d H:i:s', time() + (int) ($tokens['expires_in'] ?? 3600));

        db()->prepare(
            'UPDATE calendar_accounts SET access_token_enc = ?, refresh_token_enc = ?, token_expires_at = ?, last_sync_error = NULL WHERE id = ?'
        )->execute([
            encryptSecret($tokens['access_token']),
            encryptSecret($tokens['refresh_token'] ?? $refreshToken),
            $newExpiresAt,
            $calendarAccount['id'],
        ]);

        return $tokens['access_token'];
    }

    /**
     * Returns an array of 'Y-m-d' date strings that are busy (have at least
     * one event) for the given calendar between $start and $end (inclusive).
     */
    public static function getBusyDates(array $calendarAccount, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $accessToken = self::ensureValidAccessToken($calendarAccount);
        $mailbox = rawurlencode($calendarAccount['mailbox_email']);

        $params = [
            'startDateTime' => $start->format('Y-m-d\T00:00:00'),
            'endDateTime' => $end->format('Y-m-d\T23:59:59'),
            '$select' => 'subject,start,end,isAllDay',
            '$top' => '100',
        ];
        $path = "/users/{$mailbox}/calendar/calendarView?" . http_build_query($params);

        $busyDates = [];
        $guard = 0;
        while ($path && $guard++ < 20) {
            [$status, $json] = self::request('GET', $path, $accessToken, null, true);
            if ($status >= 400) {
                throw new RuntimeException('Graph calendarView error: ' . json_encode($json));
            }
            foreach ($json['value'] ?? [] as $event) {
                foreach (self::eventDateRange($event) as $d) {
                    $busyDates[$d] = true;
                }
            }
            $path = $json['@odata.nextLink'] ?? null;
        }

        return array_keys($busyDates);
    }

    private static function eventDateRange(array $event): array
    {
        $startTs = strtotime($event['start']['dateTime'] ?? '');
        $endTs = strtotime($event['end']['dateTime'] ?? '');
        if ($startTs === false) {
            return [];
        }
        if ($endTs === false || $endTs <= $startTs) {
            return [date('Y-m-d', $startTs)];
        }

        // Graph all-day events use an exclusive end date; back it off by a
        // second so a same-day event doesn't spill into the next date.
        $endTs -= 1;

        $dates = [];
        for ($t = $startTs; $t <= $endTs; $t += 86400) {
            $dates[] = date('Y-m-d', $t);
        }
        return $dates;
    }

    /**
     * Creates an all-day event covering exactly one calendar day.
     * Returns the new event's Graph id.
     */
    public static function createAllDayEvent(array $calendarAccount, string $isoDate, string $subject, string $body = ''): string
    {
        $accessToken = self::ensureValidAccessToken($calendarAccount);
        $mailbox = rawurlencode($calendarAccount['mailbox_email']);

        $nextDay = (new DateTimeImmutable($isoDate))->modify('+1 day')->format('Y-m-d');

        $payload = [
            'subject' => $subject,
            'body' => ['contentType' => 'text', 'content' => $body],
            'isAllDay' => true,
            'start' => ['dateTime' => $isoDate, 'timeZone' => 'Africa/Johannesburg'],
            'end' => ['dateTime' => $nextDay, 'timeZone' => 'Africa/Johannesburg'],
        ];

        [$status, $json] = self::request('POST', "/users/{$mailbox}/events", $accessToken, $payload);
        if ($status >= 400) {
            throw new RuntimeException('Graph create event error: ' . json_encode($json));
        }
        return $json['id'];
    }

    public static function deleteEvent(array $calendarAccount, string $eventId): void
    {
        $accessToken = self::ensureValidAccessToken($calendarAccount);
        $mailbox = rawurlencode($calendarAccount['mailbox_email']);

        [$status, $json] = self::request('DELETE', "/users/{$mailbox}/events/{$eventId}", $accessToken);
        // 404 just means it's already gone — treat as success.
        if ($status >= 400 && $status !== 404) {
            throw new RuntimeException('Graph delete event error: ' . json_encode($json));
        }
    }

    /**
     * @return array{0:int,1:array} [http status, decoded JSON body]
     */
    private static function request(string $method, string $path, string $accessToken, ?array $body = null, bool $pathIsFullPath = false): array
    {
        $url = $pathIsFullPath && str_starts_with($path, 'http') ? $path : self::GRAPH_BASE . $path;
        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            // Return event start/end already localized to SAST rather than
            // UTC, so naive date parsing in eventDateRange() attributes
            // events (including ones created by other calendar clients) to
            // the correct local calendar day.
            'Prefer: outlook.timezone="Africa/Johannesburg"',
        ];

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Graph request failed: ' . $err);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = $response !== '' ? (json_decode($response, true) ?? []) : [];
        return [$status, $json];
    }
}
