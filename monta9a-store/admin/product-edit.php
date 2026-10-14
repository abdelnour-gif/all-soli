<?php
require_once __DIR__ . '/_init.php';
requireAdmin();
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$product = null;
if ($id) {
    $st = $db->prepare('SELECT * FROM products WHERE id = ?');
    $st->execute([$id]);
    $product = $st->fetch();
    if (!$product) { flash('المنتج غير موجود', 'error'); redirect('products.php'); }
}
$errors = [];

$data = $product ?: [
    'name' => '', 'name_fr' => '', 'brand' => '', 'description_fr' => '', 'badge' => '', 'badge_fr' => '', 'category_id' => (int)($_GET['cat'] ?? 0), 'sku' => '',
    'price' => '', 'old_price' => '', 'stock' => 1, 'description' => '', 'images' => '[]', 'colors' => '[]', 'sizes' => '[]',
    'is_new' => 1, 'is_featured' => 0, 'active' => 1,
];

if (adminPost()) {
    $data['name'] = trim((string)($_POST['name'] ?? ''));
    $data['name_fr'] = trim((string)($_POST['name_fr'] ?? ''));
    $data['brand'] = trim((string)($_POST['brand'] ?? ''));
    $data['description_fr'] = trim((string)($_POST['description_fr'] ?? ''));
    $data['badge'] = mb_substr(trim((string)($_POST['badge'] ?? '')), 0, 24);
    $data['badge_fr'] = mb_substr(trim((string)($_POST['badge_fr'] ?? '')), 0, 24);
    $data['category_id'] = getCategory((int)($_POST['category_id'] ?? '')) ? (int)($_POST['category_id'] ?? '') : 0;
    $data['sku'] = trim((string)($_POST['sku'] ?? ''));
    $data['price'] = max(0, (int)($_POST['price'] ?? ''));
    $data['old_price'] = max(0, (int)($_POST['old_price'] ?? ''));
    $data['stock'] = max(0, (int)($_POST['stock'] ?? ''));
    $data['description'] = trim((string)($_POST['description'] ?? ''));
    $data['is_new'] = isset($_POST['is_new']) ? 1 : 0;
    $data['is_featured'] = isset($_POST['is_featured']) ? 1 : 0;
    $data['active'] = isset($_POST['active']) ? 1 : 0;

    // Colors: JSON array of #rrggbb from the color editor
    $colors = json_decode((string)($_POST['colors'] ?? '[]'), true);
    $colors = is_array($colors) ? array_values(array_unique(array_map('strtolower', array_filter($colors, 'isValidColor')))) : [];
    $data['colors'] = json_encode($colors);
    $data['sizes'] = json_encode(parseSizes((string)($_POST['sizes'] ?? '')), JSON_UNESCAPED_UNICODE);

    // Images: keep the ones not marked for removal, main image first, then new uploads
    $current = json_decode($product['images'] ?? '[]', true) ?: [];
    $remove = array_map('strval', (array)($_POST['remove_img'] ?? []));
    $keep = array_values(array_filter($current, fn($i) => !in_array($i, $remove, true)));
    $main = (string)($_POST['main_img'] ?? '');
    if ($main !== '' && in_array($main, $keep, true)) {
        $keep = array_values(array_merge([$main], array_diff($keep, [$main])));
    }
    $uploaded = [];
    if (!empty($_FILES['images']['name'][0])) {
        foreach ($_FILES['images']['name'] as $i => $n) {
            try {
                $path = uploadImage([
                    'name' => $n, 'tmp_name' => $_FILES['images']['tmp_name'][$i], 'error' => $_FILES['images']['error'][$i], 'size' => $_FILES['images']['size'][$i],
                ], 'products');
                if ($path) $uploaded[] = $path;
            } catch (RuntimeException $ex) {
                $errors[] = $n . ': ' . $ex->getMessage();
            }
        }
    }

    if ($data['name'] === '') $errors[] = 'اسم المنتج مطلوب';
    if (!$data['category_id']) $errors[] = 'يرجى اختيار القسم';
    if ($data['price'] <= 0) $errors[] = 'السعر مطلوب';
    if ($data['old_price'] && $data['old_price'] <= $data['price']) $errors[] = 'السعر القديم يجب أن يكون أكبر من السعر الحالي (أو اتركه فارغاً)';

    if ($errors) {
        foreach ($uploaded as $u) deleteUpload($u);
    } else {
        $images = array_merge($keep, $uploaded);
        foreach (array_diff($current, $keep) as $old) deleteUpload($old);
        $data['images'] = json_encode(array_values($images));
        $fields = [$data['name'], $data['category_id'], $data['sku'], $data['price'], $data['old_price'], $data['stock'], $data['description'], $data['images'], $data['colors'], $data['is_new'], $data['is_featured'], $data['active'], $data['name_fr'], $data['brand'], $data['description_fr'], $data['badge'], $data['badge_fr'], $data['sizes']];
        if ($id) {
            $db->prepare('UPDATE products SET name=?, category_id=?, sku=?, price=?, old_price=?, stock=?, description=?, images=?, colors=?, is_new=?, is_featured=?, active=?, name_fr=?, brand=?, description_fr=?, badge=?, badge_fr=?, sizes=? WHERE id=?')->execute([...$fields, $id]);
            flash('تم حفظ التعديلات');
        } else {
            $db->prepare('INSERT INTO products (name, category_id, sku, price, old_price, stock, description, images, colors, is_new, is_featured, active, name_fr, brand, description_fr, badge, badge_fr, sizes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($fields);
            $id = (int)$db->lastInsertId();
            flash('تمت إضافة المنتج');
        }
        redirect('product-edit.php?id=' . $id);
    }
}

$images = json_decode($product['images'] ?? '[]', true) ?: [];
$colors = json_decode($data['colors'] ?? '[]', true) ?: [];
$quick = ['#111111' => 'أسود', '#ffffff' => 'أبيض', '#d4af37' => 'ذهبي', '#c0c0c0' => 'فضي', '#6b3f22' => 'بني', '#f4c2c2' => 'وردي فاتح', '#d63f6b' => 'وردي', '#e0413a' => 'أحمر', '#800020' => 'عنابي', '#1f3a5f' => 'أزرق', '#f5f5dc' => 'بيج', '#808080' => 'رمادي', '#d8c3a5' => 'بيج', '#a9c1e8' => 'أزرق فاتح', '#1b2a4a' => 'كحلي', '#6b7a3a' => 'كاكي', '#b48ec7' => 'موف', '#008000' => 'أخضر'];
adminHeader($id ? 'تعديل المنتج' : 'إضافة منتج', 'products');
?>
<a href="products.php" class="back">← كل المنتجات</a>
<?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $er) echo '<div>' . e($er) . '</div>'; ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="grid-2 form" id="productForm">
  <?= csrfField() ?>
  <div>
    <div class="panel form">
      <div class="row">
        <label>اسم المنتج (بالعربية) *<input type="text" name="name" value="<?= e($data['name']) ?>" required></label>
        <label>اسم المنتج بالفرنسية (Nom en français)<input type="text" name="name_fr" value="<?= e($data['name_fr'] ?? '') ?>" dir="ltr"></label>
      </div>
      <label>العلامة التجارية (Marque) — مثلاً: La Roche-Posay<input type="text" name="brand" value="<?= e($data['brand'] ?? '') ?>" dir="ltr" list="brandList"></label>
      <datalist id="brandList"><?php foreach (settingJson('brands') as $bb): ?><option value="<?= e($bb) ?>"><?php endforeach; ?></datalist>
      <div class="row">
        <label>القسم *
          <select name="category_id" required>
            <option value="">— اختر القسم —</option>
            <?= categoryOptions((int)$data['category_id']) ?>
          </select>
        </label>
        <label>المرجع / الكود (اختياري)<input type="text" name="sku" value="<?= e($data['sku']) ?>"></label>
      </div>
      <p class="hint">لإضافة قسم جديد أو قسم فرعي: <a href="categories.php" target="_blank"><b>الأقسام</b></a>.</p>
      <div class="row three">
        <label>السعر (د.ج) *<input type="number" name="price" value="<?= e($data['price']) ?>" min="0" required></label>
        <label>السعر قبل التخفيض<input type="number" name="old_price" value="<?= e($data['old_price'] ?: '') ?>" min="0" placeholder="اختياري"></label>
        <label>المخزون *<input type="number" name="stock" value="<?= e($data['stock']) ?>" min="0" required></label>
      </div>
      <label>الوصف (بالعربية)<textarea name="description" rows="5"><?= e($data['description']) ?></textarea></label>
      <label>الوصف بالفرنسية (Description en français)<textarea name="description_fr" rows="5" dir="ltr"><?= e($data['description_fr'] ?? '') ?></textarea></label>
      <p class="hint">إذا تركت الحقول الفرنسية فارغة، تظهر النسخة العربية في الواجهة الفرنسية.</p>
    </div>

    <div class="panel form">
      <div class="panel-head"><h2>المقاسات المتوفرة</h2></div>
      <p class="hint">اكتب المقاسات مفصولة بفاصلة، مثل: <b>S, M, L, XL</b> أو <b>38, 40, 42</b>. الزبونة تختار المقاس قبل الطلب، والمقاس يظهر في الطلب وفي ملصق شركة التوصيل. اترك الخانة فارغة للمنتجات بدون مقاس (حقائب، إكسسوارات).</p>
      <input type="text" name="sizes" id="sizesInput" dir="ltr" value="<?= e(implode(', ', productSizes($data))) ?>" placeholder="S, M, L, XL">
      <div class="quick-colors">
        <?php foreach (['S, M, L, XL', 'XS, S, M, L, XL, XXL', '36, 38, 40, 42, 44', 'Taille unique'] as $preset): ?><button type="button" class="btn btn-light btn-sm" onclick="document.getElementById('sizesInput').value=this.textContent"><?= e($preset) ?></button><?php endforeach; ?>
      </div>
    </div>

    <div class="panel form">
      <div class="panel-head"><h2>الألوان المتوفرة</h2></div>
      <p class="hint">أضف الألوان التي يختار منها الزبون. تظهر في بطاقة المنتج وفي صفحة المنتج، ويُطلب من الزبون اختيار لون إذا أضفت لوناً أو أكثر.</p>
      <div class="quick-colors">
        <?php foreach ($quick as $hex => $label): ?><button type="button" class="qc" style="background:<?= $hex ?>" data-color="<?= $hex ?>" title="<?= $label ?>"></button><?php endforeach; ?>
      </div>
      <div class="color-add">
        <input type="color" id="colorPicker" value="#111111" aria-label="اختر لوناً">
        <button type="button" class="btn btn-light" id="addColor">+ إضافة هذا اللون</button>
      </div>
      <div class="color-tags" id="colorTags"></div>
      <input type="hidden" name="colors" id="colorsInput" value="<?= e(json_encode($colors)) ?>">
    </div>
  </div>

  <div>
    <div class="panel form">
      <div class="panel-head"><h2>الصور</h2></div>
      <?php if ($images): ?>
      <div class="img-grid">
        <?php foreach ($images as $i => $img): ?>
          <div class="img-item">
            <img src="../<?= e($img) ?>" alt="">
            <label class="small-check"><input type="radio" name="main_img" value="<?= e($img) ?>" <?= $i === 0 ? 'checked' : '' ?>> رئيسية</label>
            <label class="small-check red"><input type="checkbox" name="remove_img[]" value="<?= e($img) ?>"> حذف</label>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <label>رفع صور جديدة (JPG, PNG, WEBP — حتى 20MB، تُضغط تلقائياً)<input type="file" name="images[]" accept="image/*" multiple id="imgInput"></label>
      <div class="img-grid" id="imgPreview"></div>
      <p class="hint">يُفضّل صور مربعة تقريباً (مثلاً 800×700) بجودة جيدة.</p>
    </div>
    <div class="panel form">
      <label class="check"><input type="checkbox" name="active" <?= $data['active'] ? 'checked' : '' ?>> ظاهر في المتجر</label>
      <label class="check"><input type="checkbox" name="is_featured" <?= $data['is_featured'] ? 'checked' : '' ?>> يظهر في "الأكثر مبيعاً"</label>
      <label class="check"><input type="checkbox" name="is_new" <?= $data['is_new'] ? 'checked' : '' ?>> شارة "جديد"</label>
      <div class="row">
        <label>شارة خاصة (اختياري، مثلاً: الأكثر مبيعاً)<input type="text" name="badge" maxlength="24" value="<?= e($data['badge'] ?? '') ?>"></label>
        <label>الشارة بالفرنسية<input type="text" name="badge_fr" maxlength="24" value="<?= e($data['badge_fr'] ?? '') ?>" dir="ltr" placeholder="Meilleure vente"></label>
      </div>
      <button class="btn btn-gold btn-block"><?= $id ? 'حفظ التعديلات' : 'إضافة المنتج' ?></button>
      <?php if ($id): ?><a href="../product.php?id=<?= $id ?>" target="_blank" class="btn btn-light btn-block">عرض في المتجر</a><?php endif; ?>
    </div>
  </div>
</form>

<script>
(function () {
  var names = <?= json_encode($quick, JSON_UNESCAPED_UNICODE) ?>;
  var input = document.getElementById('colorsInput');
  var tags = document.getElementById('colorTags');
  var colors = [];
  try { colors = JSON.parse(input.value) || []; } catch (e) { colors = []; }

  function render() {
    input.value = JSON.stringify(colors);
    tags.innerHTML = '';
    if (!colors.length) { tags.innerHTML = '<span class="hint">لا توجد ألوان — المنتج يُباع بدون اختيار لون.</span>'; return; }
    colors.forEach(function (c) {
      var t = document.createElement('span');
      t.className = 'color-tag';
      var dot = document.createElement('span'); dot.className = 'dot'; dot.style.background = c;
      var label = document.createElement('span'); label.textContent = names[c] || c;
      var rm = document.createElement('button'); rm.type = 'button'; rm.textContent = '×'; rm.setAttribute('aria-label', 'حذف');
      rm.onclick = function () { colors = colors.filter(function (x) { return x !== c; }); render(); };
      t.append(dot, label, rm);
      tags.appendChild(t);
    });
  }
  function add(c) {
    c = String(c).toLowerCase();
    if (/^#[0-9a-f]{6}$/.test(c) && colors.indexOf(c) === -1) { colors.push(c); render(); }
  }
  document.querySelectorAll('.qc').forEach(function (b) { b.onclick = function () { add(b.dataset.color); }; });
  document.getElementById('addColor').onclick = function () { add(document.getElementById('colorPicker').value); };
  render();

  document.getElementById('imgInput').addEventListener('change', function () {
    var box = document.getElementById('imgPreview'); box.innerHTML = '';
    Array.prototype.forEach.call(this.files, function (f) {
      if (!f.type.match(/^image\//)) return;
      var img = document.createElement('img'); img.src = URL.createObjectURL(f);
      var d = document.createElement('div'); d.className = 'img-item'; d.appendChild(img); box.appendChild(d);
    });
  });
})();
</script>
<?php adminFooter(); ?>
