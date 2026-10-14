<?php
require_once __DIR__ . '/_init.php';
requireAdmin();
$db = getDB();
$editId = (int)($_GET['edit'] ?? 0);

if (adminPost()) {
    $pid = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $db->prepare('DELETE FROM pages WHERE id = ?')->execute([$pid]);
        flash('تم حذف الصفحة');
        redirect('pages.php');
    }
    $title = trim((string)($_POST['title'] ?? ''));
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', (string)($_POST['slug'] ?? '')), '-'));
    if ($title === '' || $slug === '') {
        flash('العنوان والرابط (بالحروف اللاتينية) مطلوبان', 'error');
        redirect('pages.php' . ($pid ? '?edit=' . $pid : '?edit=new'));
    }
    $dup = $db->prepare('SELECT COUNT(*) FROM pages WHERE slug = ? AND id <> ?');
    $dup->execute([$slug, $pid]);
    if ($dup->fetchColumn()) {
        flash('هذا الرابط مستعمل لصفحة أخرى', 'error');
        redirect('pages.php?edit=' . ($pid ?: 'new'));
    }
    $fields = [$slug, $title, trim((string)($_POST['content'] ?? '')), isset($_POST['in_footer']) ? 1 : 0, (int)($_POST['sort'] ?? ''), trim((string)($_POST['title_fr'] ?? '')), trim((string)($_POST['content_fr'] ?? ''))];
    if ($pid) $db->prepare('UPDATE pages SET slug=?, title=?, content=?, in_footer=?, sort=?, title_fr=?, content_fr=? WHERE id=?')->execute([...$fields, $pid]);
    else $db->prepare('INSERT INTO pages (slug, title, content, in_footer, sort, title_fr, content_fr) VALUES (?,?,?,?,?,?,?)')->execute($fields);
    flash('تم حفظ الصفحة');
    redirect('pages.php');
}
$pages = $db->query('SELECT * FROM pages ORDER BY sort, id')->fetchAll();
$edit = null;
if ($editId) foreach ($pages as $pg) if ((int)$pg['id'] === $editId) $edit = $pg;
if (($_GET['edit'] ?? '') === 'new') $edit = ['id' => 0, 'slug' => '', 'title' => '', 'title_fr' => '', 'content_fr' => '', 'content' => '', 'in_footer' => 1, 'sort' => count($pages)];
adminHeader('الصفحات', 'pages');
?>
<?php if ($edit): ?>
<a href="pages.php" class="back">← كل الصفحات</a>
<form method="post" class="panel form">
  <?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
  <div class="row">
    <label>عنوان الصفحة *<input type="text" name="title" value="<?= e($edit['title']) ?>" required></label>
    <label>الرابط (حروف لاتينية، مثلاً: shipping) *<input type="text" name="slug" value="<?= e($edit['slug']) ?>" dir="ltr" required pattern="[A-Za-z0-9\-]+"></label>
  </div>
  <label>المحتوى — اترك سطراً فارغاً بين الفقرات<textarea name="content" rows="14"><?= e($edit['content']) ?></textarea></label>
  <label>عنوان الصفحة بالفرنسية (Titre)<input type="text" name="title_fr" value="<?= e($edit['title_fr'] ?? '') ?>" dir="ltr"></label>
  <label>المحتوى بالفرنسية (Contenu) — اتركه فارغاً لإظهار النص العربي<textarea name="content_fr" rows="10" dir="ltr"><?= e($edit['content_fr'] ?? '') ?></textarea></label>
  <div class="row">
    <label>الترتيب<input type="number" name="sort" value="<?= (int)$edit['sort'] ?>"></label>
    <label class="check"><input type="checkbox" name="in_footer" <?= $edit['in_footer'] ? 'checked' : '' ?>> يظهر رابطها في أسفل الموقع (قسم مساعدة)</label>
  </div>
  <div class="btn-row"><button class="btn btn-gold">حفظ</button><?php if ($edit['slug']): ?><a href="../page.php?p=<?= e($edit['slug']) ?>" target="_blank" class="btn btn-light">عرض الصفحة</a><?php endif; ?></div>
</form>
<?php else: ?>
<div class="toolbar"><p class="hint" style="margin:0">صفحات نصية مثل: من نحن، الأسئلة الشائعة، سياسة الإرجاع... تظهر روابطها في أسفل الموقع.</p><a href="pages.php?edit=new" class="btn btn-gold">+ صفحة جديدة</a></div>
<div class="panel">
  <table class="table">
    <thead><tr><th>العنوان</th><th>الرابط</th><th>في الأسفل</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pages as $pg): ?>
      <tr><td><b><?= e($pg['title']) ?></b></td><td dir="ltr" style="text-align:right"><small>page.php?p=<?= e($pg['slug']) ?></small></td><td><?= $pg['in_footer'] ? 'نعم' : 'لا' ?></td>
        <td class="actions"><a href="pages.php?edit=<?= (int)$pg['id'] ?>" class="btn btn-light btn-sm">تعديل</a><a href="../page.php?p=<?= e($pg['slug']) ?>" target="_blank" class="btn btn-light btn-sm">عرض</a>
          <form method="post" class="inline" data-confirm="حذف الصفحة؟"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$pg['id'] ?>"><button class="btn btn-danger btn-sm">حذف</button></form></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php adminFooter(); ?>
