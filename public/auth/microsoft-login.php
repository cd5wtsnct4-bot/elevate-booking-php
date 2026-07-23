<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../lib/MicrosoftGraph.php';

if (currentUser()) {
    redirect(url(currentUser()['role'] === 'admin' ? '/admin/dashboard.php' : '/client/dashboard.php'));
}

if (MS_CLIENT_ID === '' || MS_CLIENT_SECRET === '') {
    flash('error', 'Microsoft sign-in is not configured yet.');
    redirect(url('/index.php'));
}

$state = randomToken(16);
$_SESSION['pending_ms_login'] = ['state' => $state];

redirect(MicrosoftGraph::getAuthorizationUrl($state, MicrosoftGraph::loginRedirectUri()));
