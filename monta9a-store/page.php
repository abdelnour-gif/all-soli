<?php
require_once __DIR__ . '/includes/functions.php';
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($_GET['p'] ?? '')));
$st = getDB()->prepare('SELECT * FROM pages WHERE slug = ?');
$st->execute([$slug]);
$page = $st->fetch();
if (!$page) { http_response_code(404); }
$pageTitle = $page ? tr($page, 'title') : tx('الصفحة غير موجودة', 'Page introuvable');
$activeNav = 'page-' . $slug;
require_once __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div class="container">
    <nav class="breadcrumb"><a href="index.php"><?= tx('الرئيسية', 'Accueil') ?></a> / <span><?= e($pageTitle) ?></span></nav>
    <h1><?= e($pageTitle) ?></h1>
  </div>
</section>
<section class="section">
  <div class="container narrow page-content">
    <?php if ($page): ?>
      <?php foreach (preg_split("/\n\s*\n/", trim(tr($page, 'content'))) as $para): ?>
        <p><?= nl2br(e(trim($para))) ?></p>
      <?php endforeach; ?>
      <?php if ($slug === 'about' && ($owner = setting('owner_name'))): ?><p class="owner"><?= tx('المالك:', 'Propriétaire :') ?> <strong><?= e($owner) ?></strong></p><?php endif; ?>
    <?php else: ?>
      <div class="empty-state"><h3><?= tx('الصفحة غير موجودة', 'Page introuvable') ?></h3><a href="index.php" class="btn btn-dark"><?= tx('الرئيسية', 'Accueil') ?></a></div>
    <?php endif; ?>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
