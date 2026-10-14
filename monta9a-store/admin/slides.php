<?php
require_once __DIR__ . '/_init.php';
requireAdmin();
$db = getDB();

if (adminPost()) {
    $action = $_POST['action'] ?? '';
    $sid = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'save') {
            $fields = [
                trim((string)($_POST['subtitle'] ?? '')), trim((string)($_POST['title'] ?? '')), trim((string)($_POST['title2'] ?? '')), trim((string)($_POST['text'] ?? '')),
                trim((string)($_POST['btn_text'] ?? '')), trim((string)($_POST['btn_link'] ?? '')), (int)($_POST['sort'] ?? ''), isset($_POST['active']) ? 1 : 0,
                trim((string)($_POST['subtitle_fr'] ?? '')), trim((string)($_POST['title_fr'] ?? '')), trim((string)($_POST['title2_fr'] ?? '')), trim((string)($_POST['text_fr'] ?? '')), trim((string)($_POST['btn_text_fr'] ?? '')),
            ];
            $newImg = uploadImage($_FILES['image'] ?? [], 'slides');
            if ($sid) {
                $db->prepare('UPDATE slides SET subtitle=?, title=?, title2=?, text=?, btn_text=?, btn_link=?, sort=?, active=?, subtitle_fr=?, title_fr=?, title2_fr=?, text_fr=?, btn_text_fr=? WHERE id=?')->execute([...$fields, $sid]);
                $old = $db->prepare('SELECT image FROM slides WHERE id = ?');
                $old->execute([$sid]);
                $oldImg = $old->fetchColumn();
                if ($newImg || isset($_POST['remove_image'])) {
                    deleteUpload($oldImg);
                    $db->prepare('UPDATE slides SET image = ? WHERE id = ?')->execute([$newImg ?: '', $sid]);
                }
            } else {
                $db->prepare('INSERT INTO slides (subtitle, title, title2, text, btn_text, btn_link, sort, active, subtitle_fr, title_fr, title2_fr, text_fr, btn_text_fr, image) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([...$fields, $newImg ?: '']);
            }
            flash('تم حفظ الشريحة');
        } elseif ($action === 'delete') {
            $old = $db->prepare('SELECT image FROM slides WHERE id = ?');
            $old->execute([$sid]);
            deleteUpload($old->fetchColumn());
            $db->prepare('DELETE FROM slides WHERE id = ?')->execute([$sid]);
            flash('تم حذف الشريحة');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    redirect('slides.php');
}
$slides = $db->query('SELECT * FROM slides ORDER BY sort, id')->fetchAll();
$slides[] = ['id' => 0, 'subtitle_fr' => '', 'title_fr' => '', 'title2_fr' => '', 'text_fr' => '', 'btn_text_fr' => 'Découvrir la boutique', 'subtitle' => '', 'title' => '', 'title2' => '', 'text' => '', 'btn_text' => 'تسوق الآن', 'btn_link' => 'shop.php', 'image' => '', 'sort' => count($slides) + 1, 'active' => 1];
adminHeader('شرائح الواجهة', 'slides');
?>
<p class="hint">الشرائح تظهر في أعلى الصفحة الرئيسية وتتغير تلقائياً. الصورة تظهر في جهة والنص في الجهة الأخرى فوق خلفية بيج فاتحة — ارفع صورة منتجات (1350×790 تقريباً). كل الحقول النصية اختيارية — إذا تركتها كلها فارغة تظهر الصورة وحدها بعرض كامل. الحقول الفرنسية تظهر لزوار النسخة الفرنسية (إذا تركتها فارغة يظهر النص العربي).</p>
<?php foreach ($slides as $s): ?>
<div class="panel">
  <div class="panel-head"><h2><?= $s['id'] ? 'شريحة: ' . (trim((string)$s['title']) !== '' ? e($s['title']) : 'رقم ' . (int)$s['sort']) : '+ شريحة جديدة' ?></h2></div>
  <form method="post" enctype="multipart/form-data" class="form slide-form">
    <?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
    <div class="slide-grid">
      <div class="slide-preview" <?= $s['image'] ? 'style="background-image:url(\'../' . e($s['image']) . '\')"' : '' ?>><?= $s['image'] ? '' : '<span>بدون صورة</span>' ?></div>
      <div class="form">
        <div class="row">
          <label>سطر صغير فوق العنوان (اختياري)<input type="text" name="subtitle" value="<?= e($s['subtitle']) ?>"></label>
          <label>العنوان الكبير (مثلاً: مكاتب صغيرة منزلية)<input type="text" name="title" value="<?= e($s['title']) ?>"></label>
        </div>
        <label>العنوان الثاني (مثلاً: تصميم عملي .. لمساحة أفضل)<input type="text" name="title2" value="<?= e($s['title2']) ?>"></label>
        <label>النص (يمكن كتابته على سطرين)<textarea name="text" rows="2"><?= e($s['text']) ?></textarea></label>
        <details class="fr-box" <?= trim(($s['title_fr'] ?? '') . ($s['text_fr'] ?? '')) !== '' ? 'open' : '' ?>><summary>🇫🇷 النص بالفرنسية (Français)</summary>
          <div class="form" dir="ltr">
            <div class="row">
              <label>Petite ligne au-dessus du titre<input type="text" name="subtitle_fr" value="<?= e($s['subtitle_fr'] ?? '') ?>"></label>
              <label>Grand titre<input type="text" name="title_fr" value="<?= e($s['title_fr'] ?? '') ?>"></label>
            </div>
            <label>Deuxième ligne du titre<input type="text" name="title2_fr" value="<?= e($s['title2_fr'] ?? '') ?>"></label>
            <label>Texte<textarea name="text_fr" rows="2"><?= e($s['text_fr'] ?? '') ?></textarea></label>
            <label>Texte du bouton<input type="text" name="btn_text_fr" value="<?= e($s['btn_text_fr'] ?? '') ?>"></label>
          </div>
        </details>
        <div class="row three">
          <label>نص الزر<input type="text" name="btn_text" value="<?= e($s['btn_text']) ?>"></label>
          <label>رابط الزر<input type="text" name="btn_link" value="<?= e($s['btn_link']) ?>" dir="ltr"></label>
          <label>الترتيب<input type="number" name="sort" value="<?= (int)$s['sort'] ?>"></label>
        </div>
        <div class="row">
          <label>الصورة<input type="file" name="image" accept="image/*"></label>
          <div class="checks">
            <label class="check"><input type="checkbox" name="active" <?= $s['active'] ? 'checked' : '' ?>> ظاهرة</label>
            <?php if ($s['image']): ?><label class="check"><input type="checkbox" name="remove_image"> حذف الصورة</label><?php endif; ?>
          </div>
        </div>
        <div class="btn-row">
          <button class="btn btn-dark"><?= $s['id'] ? 'حفظ' : 'إضافة الشريحة' ?></button>
        </div>
      </div>
    </div>
  </form>
  <?php if ($s['id']): ?>
  <form method="post" data-confirm="حذف هذه الشريحة؟" class="del-form"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-danger btn-sm">حذف الشريحة</button></form>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php adminFooter(); ?>
