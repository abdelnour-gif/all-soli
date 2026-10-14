<?php
require_once __DIR__ . '/_init.php';
requireAdmin();
$db = getDB();
$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId ? getCategory($editId) : null;

if (adminPost()) {
    $action = $_POST['action'] ?? '';
    $cid = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'save') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('اسم القسم مطلوب');
            $parent = (int)($_POST['parent_id'] ?? '');
            if ($cid && in_array($parent, categoryWithChildren($cid), true)) throw new RuntimeException('لا يمكن جعل القسم فرعياً لنفسه أو لأحد أقسامه الفرعية');
            $catIcon = array_key_exists($_POST['icon'] ?? '', iconChoices()) ? (string)$_POST['icon'] : '';
            $fields = [$parent, $name, $catIcon, trim((string)($_POST['description'] ?? '')), (int)($_POST['sort'] ?? ''),
                isset($_POST['show_home']) ? 1 : 0, isset($_POST['show_menu']) ? 1 : 0, isset($_POST['active']) ? 1 : 0,
                trim((string)($_POST['name_fr'] ?? '')), trim((string)($_POST['description_fr'] ?? ''))];
            $newImg = uploadImage($_FILES['image'] ?? [], 'categories');
            if ($cid) {
                $db->prepare('UPDATE categories SET parent_id=?, name=?, icon=?, description=?, sort=?, show_home=?, show_menu=?, active=?, name_fr=?, description_fr=? WHERE id=?')->execute([...$fields, $cid]);
                $old = getCategory($cid)['image'] ?? '';
                if ($newImg || isset($_POST['remove_image'])) {
                    deleteUpload($old);
                    $db->prepare('UPDATE categories SET image = ? WHERE id = ?')->execute([$newImg ?: '', $cid]);
                }
                flash('تم حفظ القسم');
            } else {
                $db->prepare('INSERT INTO categories (parent_id, name, icon, description, sort, show_home, show_menu, active, name_fr, description_fr, image) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([...$fields, $newImg ?: '']);
                flash('تمت إضافة القسم');
            }
        } elseif ($action === 'delete' && ($cat = getCategory($cid))) {
            $db->beginTransaction();
            // Sub-categories move up one level.
            $db->prepare('UPDATE categories SET parent_id = ? WHERE parent_id = ?')->execute([(int)$cat['parent_id'], $cid]);
            // Products move to the parent category. When there is no parent they
            // would end up with no category at all and still be public, so they
            // are hidden and the owner is told how many.
            $orphans = 0;
            if ((int)$cat['parent_id'] > 0) {
                $db->prepare('UPDATE products SET category_id = ? WHERE category_id = ?')->execute([(int)$cat['parent_id'], $cid]);
            } else {
                $st = $db->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
                $st->execute([$cid]);
                $orphans = (int)$st->fetchColumn();
                $db->prepare('UPDATE products SET category_id = 0, active = 0 WHERE category_id = ?')->execute([$cid]);
            }
            $db->prepare('DELETE FROM categories WHERE id = ?')->execute([$cid]);
            $db->commit();
            deleteUpload($cat['image']);
            flash('تم حذف القسم «' . $cat['name'] . '»' . ($orphans ? ' — وأُخفيت ' . $orphans . ' منتجات كانت تابعة له، عيّن لها قسماً جديداً ثم أظهرها.' : ''));
        } elseif ($action === 'toggle' && ($cat = getCategory($cid))) {
            $field = in_array($_POST['field'] ?? '', ['show_home', 'show_menu', 'active'], true) ? $_POST['field'] : 'active';
            $db->prepare("UPDATE categories SET $field = 1 - $field WHERE id = ?")->execute([$cid]);
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
        redirect('categories.php' . ($cid ? '?edit=' . $cid : ''));
    }
    redirect('categories.php');
}

$counts = $db->query('SELECT category_id, COUNT(*) c FROM products GROUP BY category_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$rows = [];
$walk = function (int $parent, int $depth) use (&$walk, &$rows) {
    foreach (allCategories(false) as $c) {
        if ((int)$c['parent_id'] !== $parent) continue;
        $c['depth'] = $depth;
        $rows[] = $c;
        $walk((int)$c['id'], $depth + 1);
    }
};
$walk(0, 0);
$form = $edit ?: ['id' => 0, 'parent_id' => (int)($_GET['parent'] ?? 0), 'name' => '', 'name_fr' => '', 'description_fr' => '', 'icon' => '', 'description' => '', 'image' => '', 'sort' => count($rows), 'show_home' => 0, 'show_menu' => 1, 'active' => 1];
adminHeader('الأقسام', 'categories');
?>
<div class="grid-2">
  <div class="panel">
    <div class="panel-head"><h2>كل الأقسام</h2><a href="categories.php#catForm" class="btn btn-gold btn-sm">+ قسم جديد</a></div>
    <p class="hint">الأقسام الرئيسية المفعّل لها "في القائمة" تظهر في شريط التنقل، وأقسامها الفرعية تظهر في القائمة المنسدلة. الأقسام المفعّل لها "في الرئيسية" تظهر كأيقونات في الصفحة الرئيسية. الترتيب: الرقم الأصغر أولاً.</p>
    <div class="table-wrap cat-table">
    <table class="table">
      <thead><tr><th></th><th>القسم</th><th>الترتيب</th><th>المنتجات</th><th>في القائمة</th><th>في الرئيسية</th><th>ظاهر</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr class="<?= $c['active'] ? '' : 'dim' ?>">
          <td><?php if ($c['image']): ?><img src="../<?= e($c['image']) ?>" class="thumb" alt=""><?php endif; ?></td>
          <td style="padding-right:<?= 10 + $c['depth'] * 22 ?>px"><?= $c['depth'] ? '↳ ' : '' ?><b><?= e($c['name']) ?></b></td>
          <td><?= (int)$c['sort'] ?></td>
          <td><a href="products.php?cat=<?= (int)$c['id'] ?>"><?= (int)($counts[$c['id']] ?? 0) ?></a></td>
          <?php foreach (['show_menu', 'show_home', 'active'] as $f): ?>
          <td>
            <?php if ($f === 'show_menu' && $c['depth'] > 0): ?><small class="muted">فرعي</small><?php else: ?>
            <form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="field" value="<?= $f ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="pill <?= $c[$f] ? 'st-delivered' : '' ?>"><?= $c[$f] ? 'نعم' : 'لا' ?></button>
            </form>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
          <td class="actions">
            <a href="categories.php?edit=<?= (int)$c['id'] ?>#catForm" class="btn btn-light btn-sm">تعديل</a>
            <a href="categories.php?parent=<?= (int)$c['id'] ?>#catForm" class="btn btn-light btn-sm" title="إضافة قسم فرعي">+ فرعي</a>
            <form method="post" class="inline" data-confirm="حذف القسم «<?= e($c['name']) ?>»؟ المنتجات والأقسام الفرعية التي بداخله تنتقل إلى القسم الأعلى."><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-danger btn-sm">حذف</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="empty">لا توجد أقسام بعد</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>

    <!-- Phone layout: one card per category -->
    <div class="cat-cards">
      <?php foreach ($rows as $c): ?>
      <div class="cat-card <?= $c['active'] ? '' : 'dim' ?> <?= $c['depth'] ? 'is-sub' : '' ?>" style="--depth:<?= min(3, (int)$c['depth']) ?>">
        <div class="cc-top">
          <span class="cc-img"><?php if ($c['image']): ?><img src="../<?= e($c['image']) ?>" alt=""><?php else: ?><?= icon(($c['icon'] ?? '') ?: 'box', 22) ?><?php endif; ?></span>
          <div class="cc-name">
            <b><?= $c['depth'] ? '↳ ' : '' ?><?= e($c['name']) ?></b>
            <small><a href="products.php?cat=<?= (int)$c['id'] ?>"><?= (int)($counts[$c['id']] ?? 0) ?> منتج</a> · الترتيب <?= (int)$c['sort'] ?></small>
          </div>
        </div>
        <div class="cc-toggles">
          <?php foreach (['show_menu' => 'في القائمة', 'show_home' => 'في الرئيسية', 'active' => 'ظاهر'] as $f => $lbl): if ($f === 'show_menu' && $c['depth'] > 0) continue; ?>
          <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="field" value="<?= $f ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button class="pill <?= $c[$f] ? 'st-delivered' : '' ?>"><?= $c[$f] ? '✓' : '✗' ?> <?= $lbl ?></button>
          </form>
          <?php endforeach; ?>
        </div>
        <div class="cc-actions">
          <a href="categories.php?edit=<?= (int)$c['id'] ?>#catForm" class="btn btn-light btn-sm">تعديل</a>
          <a href="categories.php?parent=<?= (int)$c['id'] ?>#catForm" class="btn btn-light btn-sm">+ قسم فرعي</a>
          <form method="post" data-confirm="حذف القسم «<?= e($c['name']) ?>»؟ المنتجات والأقسام الفرعية التي بداخله تنتقل إلى القسم الأعلى."><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-danger btn-sm">حذف</button></form>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (!$rows): ?><p class="empty">لا توجد أقسام بعد</p><?php endif; ?>
    </div>
  </div>

  <form method="post" enctype="multipart/form-data" class="panel form" id="catForm">
    <div class="panel-head"><h2><?= $form['id'] ? 'تعديل القسم: ' . e($form['name']) : 'إضافة قسم' ?></h2></div>
    <?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
    <label>اسم القسم (بالعربية) *<input type="text" name="name" value="<?= e($form['name']) ?>" required></label>
    <label>اسم القسم بالفرنسية (Nom en français)<input type="text" name="name_fr" value="<?= e($form['name_fr'] ?? '') ?>" dir="ltr"></label>
    <label>القسم الأب
      <select name="parent_id">
        <option value="0">— قسم رئيسي —</option>
        <?= categoryOptions((int)$form['parent_id'], (int)$form['id']) ?>
      </select>
    </label>
    <label>أيقونة القسم (تظهر داخل الدائرة الملونة في الصفحة الرئيسية إذا لم تكن للقسم صورة)
      <select name="icon">
        <option value="">— بدون أيقونة —</option>
        <?php foreach (iconChoices() as $ik => $il): ?><option value="<?= e($ik) ?>" <?= ($form['icon'] ?? '') === $ik ? 'selected' : '' ?>><?= e($il) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>وصف قصير (يظهر أعلى صفحة القسم وتحت اسم كل منتج في البطاقة، مثلاً: مكتب)<input type="text" name="description" value="<?= e($form['description']) ?>"></label>
    <label>الوصف القصير بالفرنسية (اختياري)<input type="text" name="description_fr" value="<?= e($form['description_fr'] ?? '') ?>" dir="ltr"></label>
    <label>الترتيب<input type="number" name="sort" value="<?= (int)$form['sort'] ?>"></label>
    <?php if ($form['image']): ?><img src="../<?= e($form['image']) ?>" alt="" style="max-width:160px;border-radius:10px"><label class="check"><input type="checkbox" name="remove_image"> حذف الصورة</label><?php endif; ?>
    <label>صورة القسم (اختيارية — تظهر في مربع القسم بالصفحة الرئيسية؛ يُفضّل صورة عريضة بخلفية فاتحة 340×170. بدونها تظهر صور أول منتجات القسم)<input type="file" name="image" accept="image/*"></label>
    <label class="check"><input type="checkbox" name="show_menu" <?= $form['show_menu'] ? 'checked' : '' ?>> يظهر في القائمة العلوية (للأقسام الرئيسية)</label>
    <label class="check"><input type="checkbox" name="show_home" <?= $form['show_home'] ? 'checked' : '' ?>> يظهر كأيقونة في الصفحة الرئيسية</label>
    <label class="check"><input type="checkbox" name="active" <?= $form['active'] ? 'checked' : '' ?>> ظاهر في المتجر</label>
    <button class="btn btn-gold"><?= $form['id'] ? 'حفظ التعديلات' : 'إضافة القسم' ?></button>
    <?php if ($form['id']): ?><a href="categories.php" class="btn btn-light">إلغاء</a><?php endif; ?>
  </form>
</div>
<?php adminFooter(); ?>
