<?php
declare(strict_types=1);

/**
 * Bioapicoltura Pura - deploy statico GitHub per Plesk/Apache.
 *
 * Uso: caricare questo file come deploy.php nel document root del dominio.
 * Scarica il branch GitHub come ZIP, prepara una release verificata, conserva
 * fino a 3 backup e attiva il sito senza Composer, database o build server-side.
 *
 * Requisiti: PHP 8.1+, estensioni curl e zip, HTTPS.
 */

const DEPLOY_REPOSITORY = 'jellero/apicolturapura';
const DEPLOY_BRANCH = 'main';
const DEPLOY_APP_URL = ''; // Vuoto = usa host/scheme della richiesta corrente.
const DEPLOY_LIVE_DIR = 'apicolturapura-site';
const DEPLOY_STATE_DIR = '.apicolturapura-deploy';
const DEPLOY_MAX_ARCHIVE_BYTES = 50_000_000;
const DEPLOY_MAX_EXTRACTED_BYTES = 150_000_000;
const DEPLOY_MAX_ARCHIVE_ENTRIES = 5000;
const DEPLOY_BACKUPS_TO_KEEP = 3;
const DEPLOY_DIR_MODE = 0755;
const DEPLOY_FILE_MODE = 0644;
const DEPLOY_SECRET_MODE = 0600;

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(600);

sendSecurityHeaders();
startSecureSession();

$documentRoot = __DIR__;
$stateDir = $documentRoot . DIRECTORY_SEPARATOR . DEPLOY_STATE_DIR;
$liveDir = $documentRoot . DIRECTORY_SEPARATOR . DEPLOY_LIVE_DIR;
$stateFile = $stateDir . DIRECTORY_SEPARATOR . 'state.json';

ensureStateDirectory($stateDir);

if (!isHttpsRequest() && !isLocalRequest()) {
    renderPage('HTTPS richiesto', '<div class="alert error">Apri il deploy tramite HTTPS.</div>');
    exit;
}

$config = readJsonFile($stateFile);
$action = (string) ($_POST['action'] ?? '');

if ($config === null) {
    handleFirstRun($stateFile, $action);
    exit;
}

if ($action === 'logout' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireValidCsrf();
    $_SESSION = [];
    session_regenerate_id(true);
    header('Location: ' . currentScriptUrl());
    exit;
}

if (!($_SESSION['deploy_authenticated'] ?? false)) {
    handleLogin($config, $action);
    exit;
}

if (in_array($action, ['install', 'update'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireValidCsrf();
    handleDeploy($documentRoot, $stateDir, $liveDir, $action);
    exit;
}

if ($action === 'rollback' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireValidCsrf();
    handleRollback($documentRoot, $stateDir, $liveDir);
    exit;
}

renderDeployForm($stateDir, $liveDir);

function sendSecurityHeaders(): void
{
    header_remove('X-Powered-By');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('apicolturapura_deployer');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => isHttpsRequest(),
        'samesite' => 'Strict',
        'path' => '/',
    ]);
    session_start();

    if (!isset($_SESSION['deploy_csrf'])) {
        $_SESSION['deploy_csrf'] = bin2hex(random_bytes(32));
    }
}

function isHttpsRequest(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function isLocalRequest(): bool
{
    return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
}

function currentScriptUrl(): string
{
    return (string) ($_SERVER['SCRIPT_NAME'] ?? '/deploy.php');
}

function currentSiteUrl(): string
{
    if (DEPLOY_APP_URL !== '') {
        return rtrim(DEPLOY_APP_URL, '/');
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^(?:[a-z0-9.-]+|\[[0-9a-f:]+\])(?::[0-9]{1,5})?$/i', $host)) {
        throw new RuntimeException('Host HTTP non valido: imposta DEPLOY_APP_URL nel deploy.php.');
    }

    $scheme = isHttpsRequest() ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . e((string) $_SESSION['deploy_csrf']) . '">';
}

function requireValidCsrf(): void
{
    $provided = (string) ($_POST['csrf'] ?? '');
    $expected = (string) ($_SESSION['deploy_csrf'] ?? '');

    if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
        throw new RuntimeException('Token CSRF non valido. Ricarica la pagina.');
    }
}

function ensureStateDirectory(string $stateDir): void
{
    mkdirOrFail($stateDir, 0700);
    $deny = "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";

    if (!is_file($stateDir . '/.htaccess')) {
        file_put_contents($stateDir . '/.htaccess', $deny, LOCK_EX);
    }
    if (!is_file($stateDir . '/index.html')) {
        file_put_contents($stateDir . '/index.html', '', LOCK_EX);
    }

    chmodSafe($stateDir . '/.htaccess', DEPLOY_SECRET_MODE);
    chmodSafe($stateDir . '/index.html', DEPLOY_SECRET_MODE);
}

function handleFirstRun(string $stateFile, string $action): void
{
    $error = null;

    if ($action === 'setup' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        try {
            requireValidCsrf();
            $password = (string) ($_POST['deploy_password'] ?? '');
            $confirmation = (string) ($_POST['deploy_password_confirm'] ?? '');

            if (strlen($password) < 16) {
                throw new RuntimeException('La password di deploy deve contenere almeno 16 caratteri.');
            }
            if (!hash_equals($password, $confirmation)) {
                throw new RuntimeException('Le due password non coincidono.');
            }

            writeJsonFile($stateFile, [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'created_at' => gmdate(DATE_ATOM),
            ]);

            session_regenerate_id(true);
            $_SESSION['deploy_authenticated'] = true;
            header('Location: ' . currentScriptUrl());
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }

    $body = '<div class="card"><h1>Configura il deploy</h1>'
        . '<p>Imposta una password dedicata per proteggere gli aggiornamenti da GitHub.</p>'
        . ($error ? '<div class="alert error">' . e($error) . '</div>' : '')
        . '<form method="post">' . csrfField()
        . '<input type="hidden" name="action" value="setup">'
        . field('Password deploy', 'deploy_password', 'password', '', true, 'minlength="16" autocomplete="new-password"')
        . field('Ripeti password', 'deploy_password_confirm', 'password', '', true, 'minlength="16" autocomplete="new-password"')
        . '<button type="submit">Attiva deploy</button></form></div>';

    renderPage('Configura deploy', $body);
}

function handleLogin(array $config, string $action): void
{
    $error = null;
    $blockedUntil = (int) ($_SESSION['deploy_blocked_until'] ?? 0);

    if ($action === 'login' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        try {
            requireValidCsrf();
            if ($blockedUntil > time()) {
                throw new RuntimeException('Troppi tentativi. Riprova tra qualche minuto.');
            }

            $password = (string) ($_POST['deploy_password'] ?? '');
            $hash = (string) ($config['password_hash'] ?? '');

            if ($hash === '' || !password_verify($password, $hash)) {
                $attempts = (int) ($_SESSION['deploy_login_attempts'] ?? 0) + 1;
                $_SESSION['deploy_login_attempts'] = $attempts;
                if ($attempts >= 5) {
                    $_SESSION['deploy_blocked_until'] = time() + 300;
                    $_SESSION['deploy_login_attempts'] = 0;
                }
                throw new RuntimeException('Password di deploy non valida.');
            }

            session_regenerate_id(true);
            $_SESSION['deploy_authenticated'] = true;
            $_SESSION['deploy_login_attempts'] = 0;
            unset($_SESSION['deploy_blocked_until']);
            header('Location: ' . currentScriptUrl());
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }

    $body = '<div class="card"><h1>Deploy Bioapicoltura Pura</h1>'
        . '<p>Repository: <code>' . e(DEPLOY_REPOSITORY) . '</code><br>Branch: <code>' . e(DEPLOY_BRANCH) . '</code></p>'
        . ($error ? '<div class="alert error">' . e($error) . '</div>' : '')
        . '<form method="post">' . csrfField()
        . '<input type="hidden" name="action" value="login">'
        . field('Password deploy', 'deploy_password', 'password', '', true, 'autocomplete="current-password" autofocus')
        . '<button type="submit">Accedi</button></form></div>';

    renderPage('Login deploy', $body);
}

function renderDeployForm(string $stateDir, string $liveDir, ?string $error = null, ?array $result = null): void
{
    $current = readJsonFile($liveDir . '/.deploy-meta.json');
    $currentLabel = $current !== null
        ? substr(e((string) ($current['commit'] ?? 'sconosciuto')), 0, 12) . ' · ' . e((string) ($current['deployed_at'] ?? ''))
        : (is_dir($liveDir) ? 'release esistente senza metadati' : 'nessuna release');
    $firstInstall = !is_dir($liveDir);
    $latestBackup = latestBackupDirectory($stateDir . '/backups');

    $body = '<div class="topbar"><div><strong>Bioapicoltura Pura</strong><small>Deploy Plesk + GitHub</small></div>'
        . '<form method="post" class="inline">' . csrfField() . '<input type="hidden" name="action" value="logout"><button class="secondary" type="submit">Esci</button></form></div>'
        . '<div class="card wide"><h1>' . ($firstInstall ? 'Prima installazione' : 'Aggiornamento') . '</h1>'
        . '<div class="status"><span>Repository</span><code>' . e(DEPLOY_REPOSITORY) . '</code>'
        . '<span>Branch</span><code>' . e(DEPLOY_BRANCH) . '</code>'
        . '<span>Release attuale</span><code>' . $currentLabel . '</code>'
        . '<span>Directory live</span><code>/' . e(DEPLOY_LIVE_DIR) . '</code></div>'
        . '<div class="alert info"><strong>Sito statico.</strong><br>Nessun Composer o database: il deploy scarica il commit esatto da GitHub, valida i file, crea una release, conserva i backup e aggiorna il router Apache.</div>'
        . ($error ? '<div class="alert error">' . e($error) . '</div>' : '')
        . ($result ? renderResult($result) : '');

    if ($firstInstall) {
        $body .= '<form method="post">' . csrfField()
            . '<input type="hidden" name="action" value="install">'
            . '<fieldset><legend>Prima installazione</legend><p>Il sito verrà pubblicato dalla directory <code>/' . e(DEPLOY_LIVE_DIR) . '</code> e il document root verrà instradato verso la release attiva.</p>'
            . field('Scrivi INSTALLA', 'confirmation', 'text', '', true, 'pattern="INSTALLA" autocomplete="off"')
            . '</fieldset><button type="submit">Scarica GitHub e installa</button></form>';
    } else {
        $body .= '<form method="post">' . csrfField()
            . '<input type="hidden" name="action" value="update">'
            . '<fieldset><legend>Aggiornamento</legend><p>La release attuale viene spostata nei backup prima di attivare quella nuova. Sono conservati gli ultimi ' . DEPLOY_BACKUPS_TO_KEEP . ' backup.</p>'
            . field('Scrivi AGGIORNA', 'confirmation', 'text', '', true, 'pattern="AGGIORNA" autocomplete="off"')
            . '</fieldset><button type="submit">Scarica GitHub e aggiorna</button></form>';
    }

    if ($latestBackup !== null) {
        $body .= '<hr><form method="post">' . csrfField()
            . '<input type="hidden" name="action" value="rollback">'
            . '<fieldset><legend>Rollback</legend><p>Ripristina il backup più recente: <code>' . e(basename($latestBackup)) . '</code>.</p>'
            . field('Scrivi ROLLBACK', 'confirmation', 'text', '', true, 'pattern="ROLLBACK" autocomplete="off"')
            . '</fieldset><button class="danger" type="submit">Ripristina ultimo backup</button></form>';
    }

    $body .= '</div>';
    renderPage('Deploy Bioapicoltura Pura', $body);
}

function handleDeploy(string $documentRoot, string $stateDir, string $liveDir, string $mode): void
{
    $lockHandle = null;
    $workDir = null;
    $releaseDir = null;

    try {
        validateRuntime();
        $expectedConfirmation = $mode === 'install' ? 'INSTALLA' : 'AGGIORNA';
        if ((string) ($_POST['confirmation'] ?? '') !== $expectedConfirmation) {
            throw new RuntimeException('Conferma non valida: scrivi ' . $expectedConfirmation . '.');
        }
        if ($mode === 'install' && is_dir($liveDir)) {
            throw new RuntimeException('Esiste già una release: usa Aggiornamento.');
        }
        if ($mode === 'update' && !is_dir($liveDir)) {
            throw new RuntimeException('Non esiste ancora una release: usa Prima installazione.');
        }

        $lockHandle = fopen($stateDir . '/deploy.lock', 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('È già in corso un altro deploy.');
        }

        $workDir = $stateDir . '/tmp-' . bin2hex(random_bytes(6));
        $archiveFile = $workDir . '/source.zip';
        $extractDir = $workDir . '/extract';
        $releaseDir = $stateDir . '/release-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        mkdirOrFail($workDir, 0700);
        mkdirOrFail($extractDir, 0700);

        $commit = resolveBranchCommit();
        downloadArchive($commit, $archiveFile);
        extractZipSafely($archiveFile, $extractDir);
        $sourceDir = locateSourceDirectory($extractDir);
        validateSourceTree($sourceDir);
        copyReleaseFiles($sourceDir, $releaseDir);
        fixReleasePermissions($releaseDir);
        verifyRelease($releaseDir);

        writeJsonFile($releaseDir . '/.deploy-meta.json', [
            'repository' => DEPLOY_REPOSITORY,
            'branch' => DEPLOY_BRANCH,
            'commit' => $commit,
            'deployed_at' => gmdate(DATE_ATOM),
            'mode' => $mode,
        ], DEPLOY_FILE_MODE);

        $backupDir = switchRelease($liveDir, $releaseDir, $stateDir, $commit);
        $releaseDir = null;
        installRootRouter($documentRoot, $stateDir);
        cleanupOldBackups($stateDir . '/backups', DEPLOY_BACKUPS_TO_KEEP);

        if ($workDir !== null && is_dir($workDir)) {
            removeDirectory($workDir);
            $workDir = null;
        }

        $health = checkHealth(currentSiteUrl());
        appendDeployLog($stateDir, [
            'time' => gmdate(DATE_ATOM),
            'commit' => $commit,
            'result' => ($health['ok'] ?? false) ? 'success' : 'success_health_warning',
            'mode' => $mode,
            'http_status' => $health['status'] ?? null,
            'ip' => clientIp(),
        ]);

        renderDeployForm($stateDir, $liveDir, null, [
            'commit' => $commit,
            'backup' => $backupDir,
            'health' => $health,
        ]);
    } catch (Throwable $exception) {
        if ($releaseDir !== null && is_dir($releaseDir)) {
            removeDirectory($releaseDir);
        }
        if ($workDir !== null && is_dir($workDir)) {
            removeDirectory($workDir);
        }
        appendDeployLog($stateDir, [
            'time' => gmdate(DATE_ATOM),
            'result' => 'failed',
            'message' => sanitizeLogMessage($exception->getMessage()),
            'ip' => clientIp(),
        ]);
        renderDeployForm($stateDir, $liveDir, $exception->getMessage());
    } finally {
        if (is_resource($lockHandle)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}

function handleRollback(string $documentRoot, string $stateDir, string $liveDir): void
{
    $lockHandle = null;

    try {
        if ((string) ($_POST['confirmation'] ?? '') !== 'ROLLBACK') {
            throw new RuntimeException('Conferma non valida: scrivi ROLLBACK.');
        }

        $lockHandle = fopen($stateDir . '/deploy.lock', 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('È già in corso un altro deploy.');
        }

        $backup = latestBackupDirectory($stateDir . '/backups');
        if ($backup === null) {
            throw new RuntimeException('Nessun backup disponibile.');
        }

        if (is_dir($liveDir)) {
            $failed = $stateDir . '/rolled-back-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
            if (!rename($liveDir, $failed)) {
                throw new RuntimeException('Impossibile archiviare la release corrente.');
            }
        }

        if (!rename($backup, $liveDir)) {
            throw new RuntimeException('Impossibile ripristinare il backup.');
        }

        fixReleasePermissions($liveDir);
        verifyRelease($liveDir);
        installRootRouter($documentRoot, $stateDir);
        $health = checkHealth(currentSiteUrl());
        $meta = readJsonFile($liveDir . '/.deploy-meta.json') ?? [];

        appendDeployLog($stateDir, [
            'time' => gmdate(DATE_ATOM),
            'commit' => (string) ($meta['commit'] ?? ''),
            'result' => 'rollback',
            'http_status' => $health['status'] ?? null,
            'ip' => clientIp(),
        ]);

        renderDeployForm($stateDir, $liveDir, null, [
            'commit' => (string) ($meta['commit'] ?? ''),
            'health' => $health,
            'rollback' => true,
        ]);
    } catch (Throwable $exception) {
        renderDeployForm($stateDir, $liveDir, $exception->getMessage());
    } finally {
        if (is_resource($lockHandle)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}

function validateRuntime(): void
{
    if (PHP_VERSION_ID < 80100) {
        throw new RuntimeException('Questo deploy richiede PHP 8.1 o superiore. Versione attuale: ' . PHP_VERSION);
    }
    foreach (['curl', 'zip'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('Estensione PHP mancante: ' . $extension);
        }
    }
}

function resolveBranchCommit(): string
{
    $url = 'https://api.github.com/repos/' . DEPLOY_REPOSITORY . '/commits/' . rawurlencode(DEPLOY_BRANCH);
    $response = httpRequest($url, ['Accept: application/vnd.github+json'], 30, 2_000_000);
    if ($response['status'] !== 200) {
        throw new RuntimeException('GitHub non ha restituito il commit del branch. HTTP ' . $response['status']);
    }

    $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    $sha = is_array($decoded) ? (string) ($decoded['sha'] ?? '') : '';
    if (!preg_match('/^[a-f0-9]{40}$/', $sha)) {
        throw new RuntimeException('SHA GitHub non valido.');
    }
    return $sha;
}

function downloadArchive(string $commit, string $destination): void
{
    $url = 'https://codeload.github.com/' . DEPLOY_REPOSITORY . '/zip/' . $commit;
    $file = fopen($destination, 'wb');
    $curl = curl_init($url);
    if ($file === false || $curl === false) {
        if (is_resource($file)) {
            fclose($file);
        }
        throw new RuntimeException('Impossibile inizializzare il download GitHub.');
    }

    $written = 0;
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_USERAGENT => 'BioapicolturaPura-Deployer/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($file, &$written): int {
            $length = strlen($chunk);
            if ($written + $length > DEPLOY_MAX_ARCHIVE_BYTES) {
                return 0;
            }
            $result = fwrite($file, $chunk);
            if ($result !== false) {
                $written += $result;
            }
            return $result === false ? 0 : $result;
        },
    ]);

    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $message = curl_error($curl);
    curl_close($curl);
    fclose($file);

    if ($ok === false || $status !== 200 || $written === 0) {
        @unlink($destination);
        throw new RuntimeException('Download GitHub fallito' . ($message !== '' ? ': ' . $message : ' (HTTP ' . $status . ').'));
    }
    chmodSafe($destination, DEPLOY_SECRET_MODE);
}

function httpRequest(string $url, array $headers, int $timeout, int $maxBytes): array
{
    $body = '';
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Impossibile inizializzare cURL.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'BioapicolturaPura-Deployer/1.0',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $message = curl_error($curl);
    curl_close($curl);

    if ($ok === false) {
        throw new RuntimeException('Richiesta HTTP fallita: ' . $message);
    }
    return ['status' => $status, 'body' => $body];
}

function extractZipSafely(string $archive, string $destination): void
{
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        throw new RuntimeException('Archivio GitHub non leggibile.');
    }
    if ($zip->numFiles > DEPLOY_MAX_ARCHIVE_ENTRIES) {
        $zip->close();
        throw new RuntimeException('Archivio GitHub troppo grande: troppi file.');
    }

    $expandedBytes = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $normalized = str_replace('\\', '/', $name);
        $stat = $zip->statIndex($i);
        $expandedBytes += is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;

        if (
            $normalized === ''
            || str_contains($normalized, "\0")
            || str_starts_with($normalized, '/')
            || preg_match('~(^|/)\.\.(?:/|$)~', $normalized)
        ) {
            $zip->close();
            throw new RuntimeException('Archivio GitHub non sicuro: percorso non valido.');
        }
        if ($expandedBytes > DEPLOY_MAX_EXTRACTED_BYTES) {
            $zip->close();
            throw new RuntimeException('Archivio GitHub troppo grande dopo l’estrazione.');
        }
    }

    if (!$zip->extractTo($destination)) {
        $zip->close();
        throw new RuntimeException('Estrazione archivio fallita.');
    }
    $zip->close();
}

function locateSourceDirectory(string $extractDir): string
{
    $matches = glob($extractDir . '/*', GLOB_ONLYDIR) ?: [];
    if (count($matches) !== 1) {
        throw new RuntimeException('Root del repository non trovata univocamente nello ZIP GitHub.');
    }
    return $matches[0];
}

function validateSourceTree(string $sourceDir): void
{
    $required = [
        'index.html',
        'styles.css',
        'app.js',
        'rivista/index.html',
        'r/index.html',
        'assets/qr/qr-bioapicoltura-pura-rivista.svg',
        'assets/qr/qr-bioapicoltura-pura-rivista.png',
    ];

    foreach ($required as $path) {
        if (!is_file($sourceDir . '/' . $path)) {
            throw new RuntimeException('Release GitHub incompleta: manca ' . $path);
        }
    }
}

function copyReleaseFiles(string $source, string $destination): void
{
    mkdirOrFail($destination, DEPLOY_DIR_MODE);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', $iterator->getSubPathName());
        if (shouldSkipReleasePath($relative)) {
            continue;
        }
        if ($item->isLink()) {
            throw new RuntimeException('La release contiene un link simbolico non consentito: ' . $relative);
        }

        $target = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($item->isDir()) {
            mkdirOrFail($target, DEPLOY_DIR_MODE);
        } else {
            mkdirOrFail(dirname($target), DEPLOY_DIR_MODE);
            if (!copy($item->getPathname(), $target)) {
                throw new RuntimeException('Copia file fallita: ' . $relative);
            }
            chmodSafe($target, DEPLOY_FILE_MODE);
        }
    }
}

function shouldSkipReleasePath(string $relative): bool
{
    $relative = trim($relative, '/');
    return $relative === 'deploy.php'
        || $relative === '.github'
        || str_starts_with($relative, '.github/');
}

function fixReleasePermissions(string $releaseDir): void
{
    chmodSafe($releaseDir, DEPLOY_DIR_MODE);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($releaseDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isLink()) {
            throw new RuntimeException('La release contiene un link simbolico non consentito.');
        }
        chmodSafe($item->getPathname(), $item->isDir() ? DEPLOY_DIR_MODE : DEPLOY_FILE_MODE);
    }
}

function verifyRelease(string $releaseDir): void
{
    foreach (['index.html', 'styles.css', 'app.js', 'rivista/index.html', 'r/index.html'] as $relative) {
        $path = $releaseDir . '/' . $relative;
        if (!is_file($path) || !is_readable($path) || filesize($path) === 0) {
            throw new RuntimeException('File non valido dopo il deploy: ' . $relative);
        }
    }

    foreach (['assets/qr/qr-bioapicoltura-pura-rivista.svg', 'assets/qr/qr-bioapicoltura-pura-rivista.png'] as $relative) {
        $path = $releaseDir . '/' . $relative;
        if (!is_file($path) || !is_readable($path) || filesize($path) < 100) {
            throw new RuntimeException('QR non valido dopo il deploy: ' . $relative);
        }
    }
}

function installRootRouter(string $documentRoot, string $stateDir): void
{
    $path = $documentRoot . '/.htaccess';
    if (is_file($path)) {
        $backup = $stateDir . '/root-htaccess-' . gmdate('YmdHis') . '.bak';
        @copy($path, $backup);
        if (is_file($backup)) {
            chmodSafe($backup, DEPLOY_SECRET_MODE);
        }
    }

    $live = preg_quote(DEPLOY_LIVE_DIR, '~');
    $installer = preg_quote(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '/deploy.php')), '~');
    $state = preg_quote(DEPLOY_STATE_DIR, '~');

    $router = "# BEGIN APICOLTURA PURA DEPLOY ROUTER\n"
        . "Options -Indexes\nDirectoryIndex index.html\n<IfModule mod_rewrite.c>\nRewriteEngine On\n"
        . "RewriteRule ^" . $installer . "$ - [L]\n"
        . "RewriteRule ^\\.well-known/acme-challenge/ - [L]\n"
        . "RewriteRule ^" . $state . "(?:/|$) - [F,L]\n"
        . "RewriteRule ^(?:\\.git|\\.github)(?:/|$) - [F,L,NC]\n"
        . "RewriteRule ^(?:\\.deploy-meta\\.json|SOURCES\\.md)$ - [F,L,NC]\n"
        . "RewriteCond %{THE_REQUEST} \\s/+" . $live . "(?:[/?\\s]) [NC]\n"
        . "RewriteRule ^" . $live . "(?:/|$) - [F,L]\n"
        . "RewriteCond %{DOCUMENT_ROOT}/" . DEPLOY_LIVE_DIR . "/$1 -f\n"
        . "RewriteRule ^(.+)$ " . DEPLOY_LIVE_DIR . "/$1 [L,QSA]\n"
        . "RewriteCond %{DOCUMENT_ROOT}/" . DEPLOY_LIVE_DIR . "/$1/index.html -f\n"
        . "RewriteRule ^(.+?)/?$ " . DEPLOY_LIVE_DIR . "/$1/index.html [L,QSA]\n"
        . "RewriteRule ^$ " . DEPLOY_LIVE_DIR . "/index.html [L,QSA]\n"
        . "</IfModule>\n"
        . "<IfModule mod_headers.c>\n"
        . "Header always set X-Content-Type-Options \"nosniff\"\n"
        . "Header always set X-Frame-Options \"SAMEORIGIN\"\n"
        . "Header always set Referrer-Policy \"strict-origin-when-cross-origin\"\n"
        . "</IfModule>\n# END APICOLTURA PURA DEPLOY ROUTER\n";

    if (file_put_contents($path, $router, LOCK_EX) === false) {
        throw new RuntimeException('Impossibile installare il router .htaccess.');
    }
    chmodSafe($path, DEPLOY_FILE_MODE);
}

function switchRelease(string $liveDir, string $releaseDir, string $stateDir, string $commit): ?string
{
    $backupsDir = $stateDir . '/backups';
    mkdirOrFail($backupsDir, 0700);
    $backupDir = null;

    if (is_dir($liveDir)) {
        $backupDir = $backupsDir . '/' . gmdate('YmdHis') . '-' . substr($commit, 0, 12);
        if (!rename($liveDir, $backupDir)) {
            throw new RuntimeException('Impossibile spostare la release precedente nel backup.');
        }
    }

    if (!rename($releaseDir, $liveDir)) {
        if ($backupDir !== null && is_dir($backupDir)) {
            @rename($backupDir, $liveDir);
        }
        throw new RuntimeException('Impossibile attivare la nuova release; rollback automatico eseguito.');
    }

    return $backupDir;
}

function latestBackupDirectory(string $directory): ?string
{
    if (!is_dir($directory)) {
        return null;
    }
    $backups = glob($directory . '/*', GLOB_ONLYDIR) ?: [];
    if ($backups === []) {
        return null;
    }
    usort($backups, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return $backups[0] ?? null;
}

function checkHealth(string $appUrl): array
{
    try {
        $response = httpRequest(rtrim($appUrl, '/') . '/', ['Accept: text/html,application/xhtml+xml'], 20, 1_000_000);
        return [
            'ok' => $response['status'] >= 200 && $response['status'] < 400,
            'status' => $response['status'],
        ];
    } catch (Throwable $exception) {
        return [
            'ok' => false,
            'status' => null,
            'message' => sanitizeLogMessage($exception->getMessage()),
        ];
    }
}

function mkdirOrFail(string $path, int $mode): void
{
    if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
        throw new RuntimeException('Impossibile creare la directory: ' . $path);
    }
    chmodSafe($path, $mode);
}

function chmodSafe(string $path, int $mode): void
{
    if (!@chmod($path, $mode) && !is_readable($path)) {
        throw new RuntimeException('Permessi non applicabili e percorso non leggibile: ' . $path);
    }
}

function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

function cleanupOldBackups(string $directory, int $keep): void
{
    if (!is_dir($directory)) {
        return;
    }
    $backups = glob($directory . '/*', GLOB_ONLYDIR) ?: [];
    usort($backups, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
    foreach (array_slice($backups, $keep) as $backup) {
        removeDirectory($backup);
    }
}

function readJsonFile(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $content = file_get_contents($file);
    if (!is_string($content) || $content === '') {
        return null;
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : null;
}

function writeJsonFile(string $file, array $data, int $mode = DEPLOY_SECRET_MODE): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($file, $json, LOCK_EX) === false) {
        throw new RuntimeException('Impossibile scrivere il file JSON.');
    }
    chmodSafe($file, $mode);
}

function appendDeployLog(string $stateDir, array $entry): void
{
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($stateDir . '/deploy.log', $line, FILE_APPEND | LOCK_EX);
    if (is_file($stateDir . '/deploy.log')) {
        chmodSafe($stateDir . '/deploy.log', DEPLOY_SECRET_MODE);
    }
}

function sanitizeLogMessage(string $message): string
{
    return substr($message, 0, 500);
}

function clientIp(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function renderResult(array $result): string
{
    $health = is_array($result['health'] ?? null) ? $result['health'] : [];
    $healthText = ($health['ok'] ?? false)
        ? 'Health check HTTP riuscito (' . e((string) $health['status']) . ').'
        : 'Release verificata e attiva; health check HTTP non riuscito'
            . (isset($health['status']) && $health['status'] !== null ? ' (HTTP ' . e((string) $health['status']) . ')' : '')
            . '. Verifica DNS/SSL/host.';

    $title = !empty($result['rollback']) ? 'Rollback completato.' : 'Deploy completato.';
    $class = ($health['ok'] ?? false) ? 'success' : 'warning';

    return '<div class="alert ' . $class . '"><strong>' . e($title) . '</strong><br>'
        . 'Commit: <code>' . e((string) ($result['commit'] ?? '')) . '</code><br>'
        . (!empty($result['backup']) ? 'Backup precedente: <code>' . e(basename((string) $result['backup'])) . '</code><br>' : '')
        . e($healthText)
        . '</div>';
}

function field(string $label, string $name, string $type = 'text', string $value = '', bool $required = false, string $extra = ''): string
{
    $requiredAttribute = $required ? ' required' : '';
    $valueAttribute = $type === 'password' ? '' : ' value="' . e($value) . '"';
    return '<label><span>' . e($label) . '</span><input type="' . e($type) . '" name="' . e($name) . '"' . $valueAttribute . $requiredAttribute . ' ' . $extra . '></label>';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderPage(string $title, string $body): void
{
    echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . '</title><style>'
        . ':root{font-family:Inter,system-ui,sans-serif;color:#172019;background:#eef1ec;color-scheme:light}*{box-sizing:border-box}body{margin:0;padding:24px}code{overflow-wrap:anywhere}h1{margin-top:0}.card{max-width:560px;margin:5vh auto;background:#fff;padding:28px;border-radius:18px;box-shadow:0 14px 45px #173d2b18}.wide{max-width:900px;margin:24px auto}.topbar{max-width:900px;margin:auto;display:flex;justify-content:space-between;align-items:center}.topbar small{display:block;color:#647269;margin-top:4px}.inline{margin:0}fieldset{border:1px solid #d9dfd7;border-radius:14px;padding:18px;margin:20px 0}legend{font-weight:750;padding:0 8px}label{display:block;margin:13px 0}label span{display:block;font-weight:650;margin-bottom:7px}input{width:100%;border:1px solid #b9c4ba;border-radius:10px;padding:11px 12px;font:inherit;background:#fff}button{border:0;border-radius:10px;padding:12px 18px;background:#173d2b;color:#fff;font-weight:750;cursor:pointer}.secondary{background:#dfe6df;color:#173d2b}.danger{background:#8a2d20}.alert{padding:14px 16px;border-radius:12px;margin:16px 0}.error{background:#ffe8e5;color:#7a1b10}.success{background:#e3f5e8;color:#174d28}.warning{background:#fff2cc;color:#6a4c00}.info{background:#e7eef8;color:#1f426d}.status{display:grid;grid-template-columns:140px 1fr;gap:7px 14px;background:#f3f5f1;padding:14px;border-radius:12px}.status span{color:#647269}hr{border:0;border-top:1px solid #d9dfd7;margin:28px 0}@media(max-width:700px){body{padding:12px}.card{padding:20px}.status{grid-template-columns:1fr}.topbar{align-items:flex-start}}'
        . '</style></head><body>' . $body . '</body></html>';
}
