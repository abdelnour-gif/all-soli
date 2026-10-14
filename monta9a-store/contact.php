<?php
require_once __DIR__ . '/includes/functions.php';
$sent = false;
$errors = [];
$old = ['name' => '', 'phone' => '', 'email' => '', 'message' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $k => $v) $old[$k] = trim((string)($_POST[$k] ?? ''));
    if (!checkCsrf()) $errors[] = tx('انتهت صلاحية الجلسة، حاول مرة أخرى', 'La session a expiré, réessayez');
    if (mb_strlen($old['name']) < 2) $errors[] = tx('يرجى إدخال الاسم', 'Veuillez saisir votre nom');
    if (!validPhone(normalizePhone($old['phone'])) && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = tx('يرجى إدخال رقم هاتف أو بريد إلكتروني صحيح', 'Veuillez saisir un téléphone ou un e-mail valide');
    if (mb_strlen($old['message']) < 5) $errors[] = tx('يرجى كتابة رسالتك', 'Veuillez écrire votre message');
    if (!empty($_POST['website'])) $errors[] = 'spam'; // honeypot
    if (!$errors) {
        getDB()->prepare('INSERT INTO messages (name, phone, email, message) VALUES (?,?,?,?)')
            ->execute([mb_substr($old['name'], 0, 100), normalizePhone($old['phone']), mb_substr($old['email'], 0, 150), mb_substr($old['message'], 0, 3000)]);
        $sent = true;
        $old = array_map(fn() => '', $old);
    }
}
$pageTitle = tx('اتصل بنا', 'Contactez-nous');
$activeNav = 'contact';
require_once __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div class="container">
    <nav class="breadcrumb"><a href="index.php"><?= tx('الرئيسية', 'Accueil') ?></a> / <span><?= e($pageTitle) ?></span></nav>
    <h1><?= e($pageTitle) ?></h1>
  </div>
</section>
<section class="section">
  <div class="container contact-layout">
    <div class="contact-info">
      <h2><?= tx('نحن هنا لمساعدتك', 'Nous sommes là pour vous aider') ?></h2>
      <p class="muted"><?= tx('لأي استفسار حول المنتجات أو الطلبات، تواصل معنا وسنرد عليك في أقرب وقت.', 'Une question sur un produit ou une commande ? Écrivez-nous, nous vous répondrons rapidement.') ?></p>
      <a class="ci-line" href="tel:<?= e(preg_replace('/\s+/', '', setting('phone'))) ?>"><?= icon('phone', 20) ?> <span dir="ltr"><?= e(setting('phone')) ?></span></a>
      <span class="ci-line"><?= icon('pin', 20) ?> <?= e(st('address')) ?></span>
      <?php if ($wa = preg_replace('/\D/', '', setting('whatsapp'))): if (str_starts_with($wa, '0')) $wa = '213' . substr($wa, 1); ?>
        <a class="btn btn-gold" href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('whatsapp', 18) ?> <?= tx('راسلنا على واتساب', 'Écrivez-nous sur WhatsApp') ?></a>
      <?php endif; ?>
    </div>
    <form method="post" class="card contact-form">
      <?= csrfField() ?>
      <?php if ($sent): ?><div class="alert alert-success"><?= tx('تم إرسال رسالتك بنجاح، سنتواصل معك قريباً.', 'Votre message a bien été envoyé, nous vous recontactons bientôt.') ?></div><?php endif; ?>
      <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $er) echo '<div>' . e($er) . '</div>'; ?></div><?php endif; ?>
      <div class="form-row">
        <label><?= tx('الاسم', 'Nom') ?> *<input type="text" name="name" value="<?= e($old['name']) ?>" required></label>
        <label><?= tx('رقم الهاتف', 'Téléphone') ?><input type="tel" name="phone" value="<?= e($old['phone']) ?>" placeholder="05xxxxxxxx"></label>
      </div>
      <label><?= tx('البريد الإلكتروني', 'E-mail') ?><input type="email" name="email" value="<?= e($old['email']) ?>"></label>
      <label><?= tx('رسالتك', 'Votre message') ?> *<textarea name="message" rows="5" required><?= e($old['message']) ?></textarea></label>
      <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <button class="btn btn-dark btn-block"><?= tx('إرسال', 'Envoyer') ?></button>
    </form>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
