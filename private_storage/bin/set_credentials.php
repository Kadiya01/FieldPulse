<?php

declare(strict_types=1);

/**
 * Set, reset, or revoke an agent's password credential.
 *
 *   php private_storage/bin/set_credentials.php --code=AG-001 --username=ada
 *   php private_storage/bin/set_credentials.php --code=AG-001 --revoke
 *   php private_storage/bin/set_credentials.php --code=AG-001 --username=ada --password-stdin
 *
 * With no --password and no --password-stdin, the password is read from the
 * terminal with echo disabled, which is why the command is an interactive CLI
 * rather than an HTTP endpoint. It is an operator action on a server console,
 * for the same reason enrolment is (see provision_agent.php): it sets the
 * factor everything else is derived from.
 *
 * The plaintext password is never echoed, never logged, and never written
 * anywhere. Only the bcrypt hash is stored, and the audit record carries the
 * username but never the password or the hash — a hash in an audit trail is one
 * more copy to defend, and it buys nothing over the agents table itself.
 *
 * --password-stdin exists for automation, where the alternative is passing the
 * secret in argv: argv is visible in the process list to every user on the
 * host and lands in shell history.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Console\Cli;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Domain\Validator;
use FieldPulse\Security\Credentials;

Cli::init(__FILE__);

$argv   = Cli::argv();
$agents = new AgentRepository();
$audit  = new AuditRepository();

/**
 * Read a secret without echoing it.
 *
 * The prompt goes to STDERR so that piping stdout somewhere does not capture
 * the prompt, and the value is not trimmed: a leading or trailing space is a
 * legitimate part of a passphrase, and silently stripping it would lock the
 * agent out of a password they can see they typed correctly.
 */
$readSecret = static function (string $prompt): string {
    fwrite(STDERR, $prompt);

    if (DIRECTORY_SEPARATOR === '\\') {
        /*
         * Windows has no POSIX termios, so stty is unavailable. ReadKey($true)
         * reads a key without echoing it, which is the only thing this needs to
         * do; SecureString is deliberately not used, because it protects a
         * string in managed memory and this secret was already typed in the
         * clear by the keyboard.
         */
        $hidden = @shell_exec('powershell -NoProfile -Command "$b=New-Object System.Text.StringBuilder; while($true){$c=[Console]::ReadKey($true); if($c.Key -eq [ConsoleKey]::Enter){break}; [void]$b.Append($c.KeyChar)}; $b.ToString()"');

        if (is_string($hidden)) {
            fwrite(STDERR, "\n");

            return $hidden;
        }
    }

    $sttyMode = @shell_exec('stty -g 2>/dev/null');

    if (is_string($sttyMode) && $sttyMode !== '') {
        @shell_exec('stty -echo');
    } else {
        /*
         * Nothing available to suppress echo with. Say so: the operator is
         * about to type a password in full view of the screen, its scrollback,
         * and any shoulder-surfer, and a warning they did not get to make
         * that choice is not a real choice.
         */
        Cli::warn('cannot disable terminal echo here; the password will be visible as you type');
    }

    $value = fgets(STDIN);

    if (is_string($sttyMode) && $sttyMode !== '') {
        @shell_exec('stty ' . escapeshellarg(trim($sttyMode)));
    }

    fwrite(STDERR, "\n");

    return $value === false ? '' : rtrim($value, "\r\n");
};

try {
    $code = Cli::option($argv, 'code');

    if ($code === null || $code === '') {
        Cli::fail('--code is required (the agent_code, e.g. AG-001)');
        exit(1);
    }

    $agent = $agents->findByCode($code);

    if ($agent === null) {
        Cli::fail('no agent with agent_code "' . $code . '"');
        exit(1);
    }

    $agentId = (int) $agent['id'];

    if (Cli::hasFlag($argv, 'revoke')) {
        if (!$agents->hasPasswordCredential($agentId)) {
            Cli::warn('agent "' . $code . '" had no password credential; nothing to revoke');
            exit(0);
        }

        $agents->clearCredentials($agentId);

        $audit->recordSafe([
            'actor_agent_id' => null,
            'action'         => 'agent.credentials_revoked',
            'entity_type'    => 'agent',
            'entity_id'      => $agentId,
            'metadata'       => ['agent_code' => $code],
        ]);

        Cli::ok('revoked the password credential for ' . $code);
        Cli::out('  The username "' . (string) ($agent['username'] ?? '') . '" is kept, so the same');
        Cli::out('  username can be reused when a new password is set.');
        Cli::warn('Existing refresh tokens still work until they expire or are revoked.');
        exit(0);
    }

    $username = Validator::username(Cli::option($argv, 'username') ?? '');

    $password = Cli::hasFlag($argv, 'password-stdin')
        ? (string) stream_get_contents(STDIN)
        : $readSecret('Password for ' . $username . ': ');

    // The confirmation prompt matters most when a human typed it: a typo in an
    // interactive password is otherwise found out only when the agent cannot
    // log in, usually from the field.
    if (!Cli::hasFlag($argv, 'password-stdin') && !Cli::hasFlag($argv, 'no-confirm')) {
        $confirm = $readSecret('Confirm password: ');

        if ($confirm !== $password) {
            Cli::fail('passwords did not match; nothing was changed');
            exit(1);
        }
    }

    if (trim($password) === '') {
        Cli::fail('password must not be empty; use --revoke to remove a credential instead');
        exit(1);
    }

    $existing = $agents->findByUsername($username);

    if ($existing !== null && (int) $existing['id'] !== $agentId) {
        // agents.username is UNIQUE, so this would surface as an opaque driver
        // error. Catching it here is also the honest behaviour: a username is
        // how an agent is identified, and two agents sharing one is a mistake
        // worth refusing to make silently.
        Cli::fail('username "' . $username . '" is already taken by agent ' . (string) $existing['agent_code']);
        exit(1);
    }

    $hash = Credentials::hash($password);

    $agents->setCredentials($agentId, $username, $hash);

    $audit->recordSafe([
        'actor_agent_id' => null,
        'action'         => 'agent.credentials_set',
        'entity_type'    => 'agent',
        'entity_id'      => $agentId,
        'metadata'       => [
            'agent_code' => $code,
            'username'   => $username,
        ],
    ]);

    Cli::heading('Credentials set for ' . $code);
    Cli::out('');
    Cli::out('  Username:  ' . $username);
    Cli::out('  Password:  set (bcrypt, not displayed)');
    Cli::out('');
    Cli::out('  The agent logs in with this username and password, then registers');
    Cli::out('  a device. IMEI is not involved in either step.');
    exit(0);
} catch (Throwable $e) {
    Cli::fail($e->getMessage());
    exit(1);
}
