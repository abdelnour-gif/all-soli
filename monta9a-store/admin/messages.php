<?php
require_once __DIR__ . '/_init.php';
requireAdmin();
$db = getDB();
if (adminPost()) {
    $mid = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'delete') {
        $db->prepare('DELETE FROM messages WHERE id = ?')->execute([$mid]);
        flash('تم حذف الرسالة');
    } else {
        $db->prepare('UPDATE messages SET is_read = 1 WHERE id = ?')->execute([$mid]);
    }
    redirect('messages.php');
}
$messages = $db->query('SELECT * FROM messages ORDER BY id DESC LIMIT 200')->fetchAll();
adminHeader('الرسائل', 'messages');
?>
<?php foreach ($messages as $m): ?>
<div class="panel msg <?= $m['is_read'] ? '' : 'unread' ?>">
  <div class="panel-head">
    <h2><?= e($m['name']) ?> <?php if (!$m['is_read']): ?><span class="tag">جديدة</span><?php endif; ?></h2>
    <small class="muted"><?= e($m['created_at']) ?></small>
  </div>
  <p class="msg-meta"><?php if ($m['phone']): ?><a href="tel:<?= e($m['phone']) ?>" dir="ltr"><?= e($m['phone']) ?></a><?php endif; ?> <?php if ($m['email']): ?> · <a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a><?php endif; ?></p>
  <p><?= nl2br(e($m['message'])) ?></p>
  <div class="btn-row">
    <?php if (!$m['is_read']): ?><form method="post"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-light btn-sm">تعليم كمقروءة</button></form><?php endif; ?>
    <form method="post" data-confirm="حذف الرسالة؟"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-danger btn-sm">حذف</button></form>
  </div>
</div>
<?php endforeach; ?>
<?php if (!$messages): ?><div class="panel"><p class="empty">لا توجد رسائل</p></div><?php endif; ?>
<?php adminFooter(); ?>
