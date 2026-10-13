<?php
/**
 * Phase 1 acceptance suite (spec §9). Run:  php tests/acceptance.php
 * Uses a REAL database; it drops and recreates the qp_* tables in the test DB.
 */
require __DIR__ . '/bootstrap.php';

use QueuePro\Database\Schema;
use QueuePro\Database\Seeder;
use QueuePro\Support\Actor;
use QueuePro\Support\QueueException;
use QueuePro\Support\Security;

$pass = 0;
$fail = 0;
$section = '';

function section(string $s): void
{
    global $section;
    $section = $s;
    echo "\n\033[1m■ $s\033[0m\n";
}

function ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  \033[32m✓\033[0m $label\n";
    } else {
        $fail++;
        echo "  \033[31m✗ $label\033[0m\n";
    }
}

/** Expect a QueueException with the given code. */
function expectError(string $code, callable $fn, string $label): void
{
    try {
        $fn();
        ok(false, "$label (no error thrown, expected $code)");
    } catch (QueueException $e) {
        ok($e->errorCode() === $code, "$label → $code" . ($e->errorCode() !== $code ? " (got {$e->errorCode()})" : ''));
    }
}

function parallelJoins(int $providerId, array $phones): array
{
    $start = microtime(true) + 1.5;
    $procs = [];
    foreach ($phones as $i => $phone) {
        $cmd = [PHP_BINARY, __DIR__ . '/worker_join.php', (string) $providerId, $phone, (string) $start];
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[$i] = [$procs[$i], $pipes];
    }
    $out = [];
    foreach ($procs as $i => [$p, $pipes]) {
        $json = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($p);
        $out[$i] = json_decode($json, true) ?? ['ok' => false, 'error' => 'BAD OUTPUT: ' . $json . $err];
    }
    return $out;
}

/** Books must balance: account totals == tickets' credit states == last ledger line. */
function checkBooks($c, int $providerId, string $label): void
{
    $db = $c->db;
    $acc = $db->getRow("SELECT * FROM {$db->table('credit_accounts')} WHERE provider_id = %d", [$providerId]);
    $states = $db->getRow(
        "SELECT SUM(credit_state='reserved') r, SUM(credit_state='consumed') c FROM {$db->table('tickets')} WHERE provider_id = %d",
        [$providerId]
    );
    $last = $db->getRow("SELECT * FROM {$db->table('credit_ledger')} WHERE provider_id = %d ORDER BY id DESC LIMIT 1", [$providerId]);
    $sum = $db->getRow(
        "SELECT SUM(type='reserved') res, SUM(type='released') rel, SUM(type='consumed') con FROM {$db->table('credit_ledger')} WHERE provider_id = %d",
        [$providerId]
    );
    $available = $acc['granted_total'] + $acc['purchased_total'] + $acc['adjusted_total'] - $acc['reserved'] - $acc['consumed'];
    $good = (int) $acc['reserved'] === (int) $states['r']
        && (int) $acc['consumed'] === (int) $states['c']
        && (int) $last['available_after'] === (int) $available
        && (int) $sum['res'] - (int) $sum['rel'] - (int) $sum['con'] === (int) $acc['reserved']
        && (int) $sum['con'] === (int) $acc['consumed']
        && $available >= 0;
    ok($good, "$label: books balance (available=$available reserved={$acc['reserved']} consumed={$acc['consumed']})");
}

// =====================================================================================
$c = qp_test_container();
$db = $c->db;
Schema::drop($db);
Schema::install($db);
Seeder::run($db);
Seeder::run($db); // idempotent

section('Accounts');
$adminUser = $c->users->create('owner', 'owner@example.com', 'admin', 'Owner', 'correct-horse-battery');
ok($adminUser['id'] === 1, 'admin account created');
for ($u = 2; $u <= 10; $u++) { $db->insert('qp_users', ['username' => "pad$u", 'password_hash' => 'x', 'role' => 'provider', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']); }
foreach ([11, 12, 13] as $u) { $c->users->create("prov$u", "prov$u@example.com", 'provider', null, 'provider-pass-123'); }
ok((int) $c->users->verify('OWNER', 'correct-horse-battery')['id'] === 1, 'login is case-insensitive on username');
ok($c->users->verify('owner@example.com', 'correct-horse-battery') !== null, 'login with email works');
ok($c->users->verify('owner', 'wrong-password') === null, 'wrong password rejected');
ok($c->users->verify('nobody', 'whatever-123') === null, 'unknown user rejected');
expectError('weak_password', fn() => $c->users->create('shorty', null, 'provider', null, 'short'), 'password under 10 chars');
expectError('user_exists', fn() => $c->users->create('Owner', null, 'provider'), 'duplicate username (case-insensitive)');
$verBefore = (int) $c->users->find(11)['session_version'];
$c->users->setPassword(11, 'new-provider-pass');
ok((int) $c->users->find(11)['session_version'] === $verBefore + 1 && $c->users->verify('prov11', 'new-provider-pass'), 'password change works and signs out other sessions');
$stored = $db->getVar('SELECT password_hash FROM qp_users WHERE id = 11');
ok(strpos($stored, 'new-provider-pass') === false && password_get_info($stored)['algo'] !== null, 'passwords stored as hashes only');

$admin = Actor::admin(1, Security::hashIp('10.0.0.1'));

section('Setup & free grant');
$p1 = $c->providers->create(['slug' => 'salon-elegance', 'name_ar' => 'صالون الأناقة للرجال', 'user_id' => 11, 'category_id' => 1, 'city_id' => 2], $admin);
$p1id = (int) $p1['id'];
$bal = $c->credits->balance($p1id);
ok($bal['available'] === 10 && $bal['granted'] === 10, 'new provider receives 10 free credits');
ok((int) $db->getVar("SELECT COUNT(*) FROM {$db->table('credit_ledger')} WHERE provider_id=%d AND type='free_grant' AND actor_user_id=1", [$p1id]) === 1, 'free grant written to ledger with admin id');
expectError('slug_taken', fn() => $c->providers->create(['slug' => 'salon-elegance', 'name_ar' => 'x'], $admin), 'duplicate slug');
expectError('invalid_input', fn() => $c->providers->create(['slug' => 'wp-admin', 'name_ar' => 'x'], $admin), 'reserved slug');
$owner1 = Actor::provider(11, $p1id);

section('Phone normalisation');
ok(Security::normalizePhone('0555 12 34 56') === '+213555123456', '0555 12 34 56 → +213555123456');
ok(Security::normalizePhone('+213 661-234-567') === '+213661234567', '+213 661-234-567 normalised');
ok(Security::normalizePhone('00213771234567') === '+213771234567', '00213… normalised');
ok(Security::normalizePhone('021123456') === null, 'Algerian landline rejected (not OTP-capable)');
ok(Security::normalizePhone('abc') === null, 'garbage rejected');

section('Queue open / closed');
expectError('queue_closed', fn() => $c->queue->join($p1id, ['phone' => '0550000001'], Actor::customer()), 'join while closed');
$c->queue->setQueueOpen($p1id, true, $owner1);
$c->credits->addPackage($p1id, 2, 'cash', 'first purchase', $admin); // +150
ok($c->credits->balance($p1id)['available'] === 160, 'package 150 added manually → available 160');

section('Concurrent bookings (40 phones at the same instant)');
$phones = [];
for ($i = 1; $i <= 40; $i++) {
    $phones[] = '0550' . str_pad((string) (100000 + $i), 6, '0', STR_PAD_LEFT);
}
$results = parallelJoins($p1id, $phones);
$numbers = array_column(array_filter($results, fn($r) => $r['ok']), 'number');
$errors = array_filter($results, fn($r) => !$r['ok']);
ok(count($numbers) === 40, '40/40 bookings succeeded' . ($errors ? ' — errors: ' . json_encode(array_values($errors)) : ''));
sort($numbers);
ok($numbers === range(1, 40), 'numbers are exactly 1..40, no duplicates, no gaps');
ok($c->credits->balance($p1id)['reserved'] === 40, '40 credits reserved');
checkBooks($c, $p1id, 'after concurrent joins');

section('Same phone, 10 simultaneous attempts');
$results = parallelJoins($p1id, array_fill(0, 10, '0661999999'));
$okCount = count(array_filter($results, fn($r) => $r['ok']));
$dup = count(array_filter($results, fn($r) => !$r['ok'] && $r['error'] === 'already_in_queue'));
$limited = count(array_filter($results, fn($r) => !$r['ok'] && $r['error'] === 'rate_limited'));
$inDb = (int) $db->getVar("SELECT COUNT(*) FROM {$db->table('tickets')} WHERE phone = '+213661999999'");
ok($okCount === 1 && $dup + $limited === 9 && $inDb === 1, "exactly 1 ticket; 9 refused ($dup already_in_queue, $limited rate_limited by the 6/day phone cap)");
checkBooks($c, $p1id, 'after same-phone race');

section('Serve flow: Call → In Service → Complete');
$before = $c->credits->balance($p1id);
$called = $c->queue->callNext($p1id, $owner1);
ok($called && $called['number'] === 1 && $called['status'] === 'called', 'call next → ticket #1 called');
$c->queue->transition($p1id, $called['id'], 'start', $owner1);
$done = $c->queue->transition($p1id, $called['id'], 'complete', $owner1);
$after = $c->credits->balance($p1id);
ok($done['status'] === 'completed' && $done['credit_state'] === 'consumed', 'ticket completed, credit consumed');
ok($after['consumed'] === $before['consumed'] + 1 && $after['reserved'] === $before['reserved'] - 1 && $after['available'] === $before['available'], 'consume: reserved −1, consumed +1, available unchanged');
expectError('invalid_transition', fn() => $c->queue->transition($p1id, $called['id'], 'complete', $owner1), 'complete twice');
ok($c->queue->liveStatus($p1id)['current'] === 'A001', 'live status shows current number A001');
checkBooks($c, $p1id, 'after complete');

section('Customer cancel via link token');
$t = $c->queue->join($p1id, ['phone' => '0770123456', 'name' => '<b>Ali</b>'], Actor::customer());
$view = $c->queue->ticketByToken($t['token']);
ok($view['status'] === 'waiting' && $view['ahead'] === 40, "ticket view: waiting, {$view['ahead']} ahead");
ok($view['phone_masked'] !== '+213770123456', 'customer view masks the phone');
$bal = $c->credits->balance($p1id)['available'];
$c->queue->cancelByToken($t['token'], Actor::customer());
ok($c->credits->balance($p1id)['available'] === $bal + 1, 'cancel releases the reserved credit');
expectError('invalid_transition', fn() => $c->queue->cancelByToken($t['token'], Actor::customer()), 'cancel twice');
expectError('ticket_not_found', fn() => $c->queue->ticketByToken('forged-token-123'), 'forged token');
$again = $c->queue->join($p1id, ['phone' => '0770123456'], Actor::customer());
ok($again['ticket']['status'] === 'waiting', 'same phone can book again after cancelling');
$name = $db->getVar("SELECT customer_name FROM {$db->table('tickets')} WHERE public_ref=%s", [$t['ticket']['ref']]);
ok($name === 'Ali', 'HTML stripped from customer name (XSS)');

section('No-show policy');
$q = $c->queue->providerQueue($p1id, $owner1);
$waiting = array_values(array_filter($q['active'], fn($x) => $x['status'] === 'waiting'));
$bal = $c->credits->balance($p1id);
$c->queue->transition($p1id, $waiting[0]['id'], 'no_show', $owner1);
$b2 = $c->credits->balance($p1id);
ok($b2['consumed'] === $bal['consumed'] && $b2['available'] === $bal['available'] + 1, 'default: no-show releases, no deduction');
$c->settings->set('consume_on_no_show', 1, $admin, $c->audit);
$c->queue->transition($p1id, $waiting[1]['id'], 'no_show', $owner1);
ok($c->credits->balance($p1id)['consumed'] === $b2['consumed'] + 1, 'policy switched in settings: no-show now consumes');
$c->settings->set('consume_on_no_show', 0, $admin, $c->audit);
checkBooks($c, $p1id, 'after no-shows');

section('Delayed (once, back to the end)');
$q = $c->queue->providerQueue($p1id, $owner1);
$waiting = array_values(array_filter($q['active'], fn($x) => $x['status'] === 'waiting'));
$target = $waiting[0];
$c->queue->transition($p1id, $target['id'], 'call', $owner1);
$d = $c->queue->transition($p1id, $target['id'], 'delay', $owner1);
ok($d['status'] === 'waiting' && $d['delayed'] === true && $d['number'] === $target['number'], 'delayed: back to waiting, same number, flagged');
$q = $c->queue->providerQueue($p1id, $owner1);
$waitingNow = array_values(array_filter($q['active'], fn($x) => $x['status'] === 'waiting'));
ok(end($waitingNow)['id'] === $target['id'], 'delayed ticket is now last in the queue');
$c->queue->transition($p1id, $target['id'], 'call', $owner1);
expectError('already_delayed', fn() => $c->queue->transition($p1id, $target['id'], 'delay', $owner1), 'second delay');

section('Idempotency (double tap / network retry)');
$key = 'b4b1c2d3-e5f6-4711-9a0b-1c2d3e4f5a6b';
$r1 = $c->queue->join($p1id, ['phone' => '0661000111'], Actor::customer(), $key);
$r2 = $c->queue->join($p1id, ['phone' => '0661000111'], Actor::customer(), $key);
ok($r1['ticket']['ref'] === $r2['ticket']['ref'] && $r1['token'] === $r2['token'] && !empty($r2['replayed']), 'same Idempotency-Key → same ticket & link, nothing double-booked');

section('Rate limiting: customers sharing one Wi-Fi network (account-less abuse)');
$cafe = $c->providers->create(['slug' => 'wifi-cafe', 'name_ar' => 'مقهى الشبكة'], $admin);
$cafeId = (int) $cafe['id'];
$c->credits->addCredits($cafeId, 'purchased', 200, $admin, ['reason' => 'test']);
$c->queue->setQueueOpen($cafeId, true, $admin);
$wifi = Actor::customer(Security::hashIp('203.0.113.9'));     // one public IP for everybody in the shop
$dev = fn(int $n) => sprintf('phone-%04d-aaaaaaaaaaaa', $n);  // each customer's own phone
for ($i = 0; $i < 5; $i++) { // typos first: refused attempts must not use anybody's place
    try { $c->queue->join($cafeId, ['phone' => '12345', 'device' => $dev(90)], $wifi); } catch (QueueException $e) {}
}
$okJoins = 0;
for ($i = 0; $i < 6; $i++) {
    $c->queue->join($cafeId, ['phone' => '07990000' . sprintf('%02d', $i), 'device' => $dev($i)], $wifi);
    $okJoins++;
}
ok($okJoins === 6, '6 customers on the same Wi-Fi book without any extra step (5 mistyped numbers before did not count)');
expectError('otp_required', fn() => $c->queue->join($cafeId, ['phone' => '0799000006', 'device' => $dev(6)], $wifi),
    '7th customer on the same Wi-Fi is NOT refused: asked to confirm the phone by SMS');
$c->queue->requestOtp($cafeId, '0799000006', 'join', 'ar', $wifi);
$code = $db->getVar("SELECT body FROM {$db->table('notifications')} WHERE type='otp' AND to_phone='+213799000006' ORDER BY id DESC LIMIT 1");
preg_match('/\d{6}/', (string) $code, $m);
$t7 = $c->queue->join($cafeId, ['phone' => '0799000006', 'device' => $dev(6), 'otp_code' => $m[0] ?? ''], $wifi);
ok($t7['ticket']['status'] === 'waiting', '…with the SMS code the 7th real customer gets a ticket');
expectError('otp_required', fn() => $c->queue->join($cafeId, ['phone' => '0799000077', 'device' => $dev(7)], $wifi),
    'a script inventing phone numbers cannot get past the SMS step');

$c->settings->set('max_joins_per_ip_window', 100, $admin, $c->audit); // isolate the next checks from the SMS step
$home = Actor::customer(Security::hashIp('198.51.100.20'));
$four = Actor::customer(Security::hashIp('198.51.100.21'));
for ($i = 0; $i < 3; $i++) {
    $c->queue->join($cafeId, ['phone' => '06610000' . $i . '1', 'device' => 'one-phone-books-for-everyone'], $home);
}
expectError('rate_limited', fn() => $c->queue->join($cafeId, ['phone' => '0661000091', 'device' => 'one-phone-books-for-everyone'], $four),
    'one phone cannot book a 4th number in 15 min (even after switching network)');
$bot = Actor::customer(Security::hashIp('198.51.100.30'));
for ($i = 0; $i < 3; $i++) {
    $c->queue->join($cafeId, ['phone' => '05510000' . $i . '2'], $bot);
}
expectError('rate_limited', fn() => $c->queue->join($cafeId, ['phone' => '0551000092'], $bot),
    'requests without a device id (scripts) share one strict limit per network');
$c->settings->set('max_joins_per_ip_hard', 8, $admin, $c->audit);
$office = Actor::customer(Security::hashIp('198.51.100.40'));
for ($i = 0; $i < 8; $i++) {
    $c->queue->join($cafeId, ['phone' => '07710000' . $i . '3', 'device' => $dev(200 + $i)], $office);
}
expectError('rate_limited', fn() => $c->queue->join($cafeId, ['phone' => '0771000093', 'device' => $dev(300)], $office),
    'hard ceiling per network still stops a flood (rotating device ids)');
$c->settings->set('max_joins_per_ip_hard', 30, $admin, $c->audit);
$c->settings->set('max_joins_per_ip_window', 6, $admin, $c->audit);
checkBooks($c, $cafeId, 'wifi-cafe');

section('Zero credit: stop, keep serving, resume');
$p2 = $c->providers->create(['slug' => 'pharmacie-salam', 'name_ar' => 'صيدلية السلام', 'user_id' => 12], $admin);
$p2id = (int) $p2['id'];
$owner2 = Actor::provider(12, $p2id);
$c->queue->setQueueOpen($p2id, true, $owner2);
$tokens = [];
for ($i = 0; $i < 10; $i++) {
    $tokens[] = $c->queue->join($p2id, ['phone' => '05600000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)], Actor::customer())['token'];
}
ok($c->credits->balance($p2id)['available'] === 0, '10 bookings use all 10 free credits → available 0');
expectError('booking_paused', fn() => $c->queue->join($p2id, ['phone' => '0560000099'], Actor::customer()), '11th booking');
$live = $c->queue->liveStatus($p2id);
ok($live['accepting'] === false && $live['reason'] === 'booking_paused', 'public status shows booking paused message');
expectError('insufficient_credit', fn() => $c->queue->addWalkIn($p2id, $owner2, 'زبون'), 'walk-in at zero credit');
$n = $c->queue->callNext($p2id, $owner2);
$c->queue->transition($p2id, $n['id'], 'complete', $owner2);
ok($c->credits->balance($p2id)['consumed'] === 1, 'provider still serves the queue while at zero');
$c->queue->cancelByToken($tokens[5], Actor::customer());
ok($c->queue->liveStatus($p2id)['accepting'] === true, 'a cancellation frees a credit → booking resumes automatically');
$c->queue->join($p2id, ['phone' => '0560000099'], Actor::customer());
ok($c->queue->liveStatus($p2id)['accepting'] === false, 'back to zero after that booking');
$c->credits->addPackage($p2id, 1, 'baridimob', 'recharge', $admin);
ok($c->queue->liveStatus($p2id)['accepting'] === true, 'admin recharge → booking resumes automatically');
$walk = $c->queue->addWalkIn($p2id, $owner2, 'زبون حاضر');
ok($walk['source'] === 'walkin' && $walk['phone'] === null, 'walk-in added without phone');
$c->queue->transition($p2id, $walk['id'], 'start', $owner2);
$w = $c->queue->transition($p2id, $walk['id'], 'complete', $owner2);
ok($w['credit_state'] === 'consumed', 'walk-in consumes a credit on completion');
expectError('insufficient_credit', fn() => $c->credits->addCredits($p2id, 'adjustment', -1000, $admin, ['reason' => 'test']), 'negative adjustment below zero refused');
checkBooks($c, $p2id, 'provider 2');

section('Security: provider isolation (IDOR) & roles');
$p2ticket = (int) $db->getVar("SELECT id FROM {$db->table('tickets')} WHERE provider_id=%d AND status='waiting' LIMIT 1", [$p2id]);
expectError('ticket_not_found', fn() => $c->queue->transition($p1id, $p2ticket, 'complete', $owner1), "provider 1 completes provider 2's ticket via own scope");
expectError('forbidden', fn() => $c->queue->transition($p2id, $p2ticket, 'complete', $owner1), "provider 1 targets provider 2 directly");
expectError('forbidden', fn() => $c->queue->providerQueue($p2id, $owner1), "provider 1 reads provider 2's queue");
expectError('forbidden', fn() => $c->credits->addCredits($p1id, 'purchased', 100, $owner1), 'provider adds credits to himself');
expectError('forbidden', fn() => $c->providers->setStatus($p2id, 'blocked', $owner1), 'provider blocks another provider');
expectError('forbidden', fn() => $c->queue->transition($p1id, 1, 'complete', Actor::customer()), 'customer calls provider action');
expectError('forbidden', fn() => $c->providers->saveService($p2id, ['name_ar' => 'x'], $owner1), "provider edits another provider's services");
$svc = $c->providers->saveService($p1id, ['name_ar' => 'حلاقة الشعر', 'price' => 400], $owner1);
expectError('invalid_service', fn() => $c->queue->join($p2id, ['phone' => '0561111111', 'service_id' => $svc['id']], Actor::customer()), "booking provider 2 with provider 1's service");
$sqli = $c->providers->search(['q' => "' OR 1=1 -- "]);
ok($sqli === [], 'SQL injection in search returns nothing');

section('Freeze & block');
$c->providers->setStatus($p2id, 'frozen', $admin, 'unpaid');
expectError('provider_unavailable', fn() => $c->queue->join($p2id, ['phone' => '0562222222'], Actor::customer()), 'frozen: no new bookings');
$q2 = $c->queue->providerQueue($p2id, $owner2);
ok(count($q2['active']) > 0, 'frozen: provider can still see & serve the current queue');
$c->providers->setStatus($p2id, 'blocked', $admin, 'abuse');
expectError('provider_not_found', fn() => $c->providers->getPublicBySlug('pharmacie-salam'), 'blocked: hidden from the public');
expectError('forbidden', fn() => $c->queue->providerQueue($p2id, $owner2), 'blocked: provider dashboard locked');
$c->providers->setStatus($p2id, 'active', $admin);

section('End of day (lazy rollover, provider timezone)');
$waitingBefore = (int) $db->getVar("SELECT COUNT(*) FROM {$db->table('tickets')} WHERE provider_id=%d AND status IN ('waiting','called','in_service')", [$p1id]);
$c->clock->freeze((new DateTimeImmutable('tomorrow 09:00', new DateTimeZone('Africa/Algiers')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
$reservedBefore = $c->credits->balance($p1id)['reserved'];
$c->queue->setQueueOpen($p1id, true, $owner1);
$new = $c->queue->join($p1id, ['phone' => '0550100001'], Actor::customer()); // first touch of the new day
$expired = (int) $db->getVar("SELECT COUNT(*) FROM {$db->table('tickets')} WHERE provider_id=%d AND status='expired' AND closed_reason='end_of_day'", [$p1id]);
ok($expired === $waitingBefore && $waitingBefore > 0, "$expired leftover tickets expired automatically");
ok($c->credits->balance($p1id)['reserved'] === 1, "their reserved credits released (reserved $reservedBefore → 1, the new ticket)");
ok($new['ticket']['number'] === 1, 'numbering restarts at 1 on the new day');
$tz = $c->providers->create(['slug' => 'riyadh-clinic', 'name_ar' => 'عيادة', 'timezone' => 'Asia/Riyadh'], $admin);
$c->clock->freeze('2026-10-11 22:30:00');
ok($c->clock->localDate('Asia/Riyadh') === '2026-10-12' && $c->clock->localDate('Africa/Algiers') === '2026-10-11', 'queue date follows each provider\'s timezone (22:30 UTC = next day in Riyadh)');
checkBooks($c, $p1id, 'after rollover');

section('Ledger & audit trail');
$types = array_column($db->getResults("SELECT DISTINCT type FROM {$db->table('credit_ledger')}"), 'type');
sort($types);
ok($types === ['consumed', 'free_grant', 'purchased', 'released', 'reserved'], 'ledger holds every movement type used: ' . implode(', ', $types));
$neg = (int) $db->getVar("SELECT COUNT(*) FROM {$db->table('credit_ledger')} WHERE available_after < 0");
ok($neg === 0, 'no ledger line ever shows a negative balance');
$actions = array_column($db->getResults("SELECT DISTINCT action FROM {$db->table('audit_log')}"), 'action');
foreach (['provider.create', 'credits.free_grant', 'credits.purchased', 'provider.status', 'settings.update'] as $a) {
    ok(in_array($a, $actions, true), "audit log records $a");
}
try {
    $db->query("UPDATE {$db->table('credit_accounts')} SET reserved = reserved + 100000 WHERE provider_id = %d", [$p1id]);
    ok(false, 'database CHECK blocks negative available');
} catch (\QueuePro\Database\DbException $e) {
    ok(true, 'database CHECK constraint blocks a negative balance even from raw SQL');
}


section('Phase 5: OTP verification');
$c->clock->freeze('2026-10-12 10:00:00');
$p3 = $c->providers->create(['slug' => 'otp-shop', 'name_ar' => 'محل التحقق', 'name_en' => 'OTP Shop', 'user_id' => 13], $admin);
$p3id = (int) $p3['id'];
$owner3 = Actor::provider(13, $p3id);
$c->queue->setQueueOpen($p3id, true, $owner3);
$c->settings->set('require_otp', 1, $admin, $c->audit);
$c->settings->set('max_joins_per_ip_window', 100, $admin, $c->audit);
$lastOtp = function (string $phone) use ($db) {
    $b = $db->getVar("SELECT body FROM {$db->table('notifications')} WHERE type='otp' AND to_phone=%s ORDER BY id DESC LIMIT 1", [$phone]);
    preg_match('/\d{6}/', (string) $b, $m);
    return $m[0] ?? null;
};
expectError('otp_required', fn() => $c->queue->join($p3id, ['phone' => '0551112233'], Actor::customer()), 'join without code when OTP is on');
$r = $c->queue->requestOtp($p3id, '0551112233', 'join', 'ar', Actor::customer());
ok($r['expires_in'] === 300, 'code sent, valid 5 minutes');
$code = $lastOtp('+213551112233');
ok($code !== null, 'SMS with 6-digit code queued in the outbox');
expectError('otp_cooldown', fn() => $c->queue->requestOtp($p3id, '0551112233', 'join', 'ar', Actor::customer()), 'second code within 60 s');
expectError('otp_invalid', fn() => $c->queue->join($p3id, ['phone' => '0551112233', 'otp_code' => $code === '000000' ? '111111' : '000000'], Actor::customer()), 'wrong code');
$attempts = (int) $db->getVar("SELECT attempts FROM {$db->table('otp_codes')} ORDER BY id DESC LIMIT 1");
ok($attempts === 1, 'failed attempt is counted (not rolled back)');
$t3 = $c->queue->join($p3id, ['phone' => '0551112233', 'otp_code' => $code, 'lang' => 'en'], Actor::customer());
ok($t3['ticket']['status'] === 'waiting', 'correct code → ticket created');
expectError('otp_expired', fn() => $c->queue->join($p3id, ['phone' => '0662223344', 'otp_code' => $code], Actor::customer()), 'code cannot be reused / used for another phone');
$c->queue->requestOtp($p3id, '0662223344', 'join', 'ar', Actor::customer());
$code2 = $lastOtp('+213662223344');
for ($i = 0; $i < 5; $i++) {
    try { $c->otp->verify('+213662223344', 'join', '99999' . $i); } catch (QueueException $e) {}
}
expectError('otp_expired', fn() => $c->queue->join($p3id, ['phone' => '0662223344', 'otp_code' => $code2], Actor::customer()), 'code locked after 5 wrong attempts (even the right one)');
$c->clock->freeze('2026-10-12 10:10:00');
$c->queue->requestOtp($p3id, '0773334455', 'join', 'ar', Actor::customer());
$code3 = $lastOtp('+213773334455');
$c->clock->freeze('2026-10-12 10:16:00');
expectError('otp_expired', fn() => $c->queue->join($p3id, ['phone' => '0773334455', 'otp_code' => $code3], Actor::customer()), 'code expires after 5 minutes');

section('Phase 5: lost link recovery');
$old = $t3['token'];
expectError('no_active_ticket', fn() => $c->queue->requestOtp($p3id, '0559998877', 'recover', 'ar', Actor::customer()), 'recovery for a phone without ticket');
$c->queue->requestOtp($p3id, '0551112233', 'recover', 'ar', Actor::customer());
$rec = $c->queue->recoverTicket($p3id, '0551112233', $lastOtp('+213551112233'), Actor::customer());
ok($rec['ticket']['ref'] === $t3['ticket']['ref'] && $rec['token'] !== $old, 'same ticket, new link issued');
expectError('ticket_not_found', fn() => $c->queue->ticketByToken($old), 'old link no longer works');
$c->settings->set('require_otp', 0, $admin, $c->audit);

section('Phase 5: notifications');
$c->notify->baseUrl = 'https://example.test';
$c->settings->set('notify_on_join', 1, $admin, $c->audit);
$c->settings->set('notify_near_ahead', 2, $admin, $c->audit);
$tk = [];
foreach (['0550000101', '0550000102', '0550000103', '0550000104', '0550000105'] as $i => $ph) {
    $tk[] = $c->queue->join($p3id, ['phone' => $ph, 'name' => 'C' . $i], Actor::customer());
}
$joined = $db->getRow("SELECT * FROM {$db->table('notifications')} WHERE type='joined' AND to_phone='+213550000105'");
ok($joined && strpos($joined['body'], 'https://example.test/t/' . $tk[4]['token'] . '/') !== false, 'booking SMS carries the personal ticket link');
$countNear = fn($ph) => (int) $db->getVar("SELECT COUNT(*) FROM {$db->table('notifications')} WHERE type='near' AND to_phone=%s", [$ph]);
// queue: 551112233(A001), 101..105 → call A001, complete → front moves
$first = $c->queue->callNext($p3id, $owner3);
ok((int) $db->getVar("SELECT COUNT(*) FROM {$db->table('notifications')} WHERE type='called' AND ticket_id=%d", [$first['id']]) === 1, '"your turn" SMS queued on call');
$c->queue->transition($p3id, $first['id'], 'complete', $owner3);
ok($countNear('+213550000103') === 1, 'customer with 2 ahead gets "almost your turn"');
$c->queue->callNext($p3id, $owner3);
$c->queue->callNext($p3id, $owner3);
ok($countNear('+213550000103') === 1, '…and only once');
ok($countNear('+213550000104') === 1 && $countNear('+213550000105') === 1, 'next customers notified as the queue moves');
ok($countNear('+213550000101') === 0, 'customers who joined already near the front are not spammed');
$en = $db->getVar("SELECT body FROM {$db->table('notifications')} WHERE type='called' AND to_phone='+213551112233'");
ok(strpos((string) $en, "It's your turn") !== false || strpos((string) $en, "it's your turn") !== false, 'message language follows the customer (English booking → English SMS)');
$c->settings->set('notify_on_join', 0, $admin, $c->audit);

section('Phase 6: ratings');
$doneTok = $rec['token'];
expectError('rating_not_allowed', fn() => $c->ratings->rate($tk[4]['token'], 5, 'x'), 'rating a ticket that is not completed');
$c->ratings->rate($doneTok, 5, '<script>x</script>خدمة ممتازة');
expectError('already_rated', fn() => $c->ratings->rate($doneTok, 1, 'again'), 'second rating for the same ticket');
expectError('invalid_input', fn() => $c->ratings->rate($doneTok, 9, ''), 'stars outside 1..5');
$mid = $c->queue->providerQueue($p3id, $owner3)['active'];
$calledIds = array_values(array_filter($mid, fn($x) => $x['status'] === 'called'));
$c->queue->transition($p3id, $calledIds[0]['id'], 'complete', $owner3);
$tokFor = $tk[0]['token'];
$c->ratings->rate($tokFor, 2, null);
$pv = $c->providers->get($p3id);
ok((float) $pv['rating_avg'] === 3.5 && (int) $pv['rating_count'] === 2, 'provider average 3.5 from 2 ratings');
$list = $c->ratings->listPublic($p3id);
ok(strpos($list[1]['comment'], '<script>') === false, 'review HTML stripped');
$rid = (int) $db->getVar("SELECT id FROM {$db->table('ratings')} WHERE stars = 2");
$c->ratings->setVisible($rid, false, $admin);
$pv = $c->providers->get($p3id);
ok((float) $pv['rating_avg'] === 5.0 && (int) $pv['rating_count'] === 1, 'admin hides a review → average recalculated');
expectError('forbidden', fn() => $c->ratings->setVisible($rid, true, $owner3), 'provider cannot moderate reviews');
$c->clock->freeze('2026-10-25 10:00:00');
expectError('rating_not_allowed', fn() => $c->ratings->rate($tk[1]['token'], 4, null), 'rating window closes after 7 days');

section('Migrations');
ok(\QueuePro\Database\Migrations::run($db) === 0 && \QueuePro\Database\Migrations::run($db) === 0, 'migration runner is idempotent');
Schema::install($db);
ok((int) $db->getVar("SELECT COUNT(*) FROM qp_users") > 0, 're-running the installer keeps all data');

section('Settings safety');
$c->settings->set('twilio_secret', 'sk_live_ABCDEFGH1234', $admin, $c->audit);
ok($c->settings->forDisplay()['twilio_secret'] === '••••1234', 'API secrets are masked when displayed');
$c->settings->set('twilio_secret', '••••1234', $admin, $c->audit);
ok($c->settings->string('twilio_secret') === 'sk_live_ABCDEFGH1234', 'saving the masked value keeps the real secret');
expectError('invalid_input', fn() => $c->settings->set('sms_http_url', 'http://insecure.example', $admin, $c->audit), 'SMS gateway URL must be HTTPS');
$audit = $db->getVar("SELECT details FROM {$db->table('audit_log')} WHERE action='settings.update' AND details LIKE '%twilio_secret%' ORDER BY id DESC LIMIT 1");
ok(strpos((string) $audit, 'sk_live') === false, 'secret values never written to the audit log');

section('Admin stats');
$s = $c->stats->overview();
ok($s['providers']['total'] === 5 && $s['credits']['purchased'] === 400, "stats: {$s['providers']['total']} providers, {$s['credits']['purchased']} credits sold, {$s['credits']['consumed']} consumed");

echo "\n" . ($fail === 0 ? "\033[42;30m PASS \033[0m" : "\033[41;37m FAIL \033[0m") . " $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
