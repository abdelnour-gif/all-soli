<?php
require_once dirname(__DIR__) . '/includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
$out = fn(bool $ok, string $msg) => exit(json_encode(['success' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE));
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !checkCsrf()) $out(false, tx('انتهت صلاحية الصفحة، أعد تحميلها', 'Page expirée, rechargez-la'));
if (setting('newsletter_on') !== '1') $out(false, tx('الاشتراك غير متاح حالياً', 'L\'inscription n\'est pas disponible'));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) $out(false, tx('يرجى إدخال بريد إلكتروني صحيح', 'Veuillez saisir un e-mail valide'));
if (($_SESSION['subscribed_at'] ?? 0) > time() - 30) $out(false, tx('يرجى الانتظار قليلاً', 'Veuillez patienter un instant'));
$_SESSION['subscribed_at'] = time();
$st = getDB()->prepare('INSERT OR IGNORE INTO subscribers (email) VALUES (?)');
$st->execute([$email]);
$out(true, $st->rowCount() ? tx('شكراً! تم اشتراكك بنجاح', 'Merci ! Inscription réussie') : tx('أنت مشترك بالفعل، شكراً!', 'Vous êtes déjà inscrit, merci !'));
