<?php
require_once __DIR__ . '/_init.php';
requireAdmin();
$db = getDB();
$labels = homeSectionLabels();
const TOPBAR_SLOTS = 4;
const BANNER_SLOTS = 4;
const FEATURE_SLOTS = 6;
const WHY_SLOTS = 4;
const PILL_SLOTS = 4;
const PROMO_SLOTS = 3;
const APPEARANCE_FR = ['search_placeholder', 'newest_sub', 'featured_title', 'featured_sub', 'newest_title', 'sale_title', 'features_title', 'why_title', 'cats_title', 'cats_kicker', 'reviews_title', 'reviews_sub', 'footer_about', 'footer_tagline', 'newsletter_title', 'newsletter_text', 'promo_pill', 'promo_title', 'promo_text', 'promo_btn', 'brands_title', 'cta_title', 'cta_text'];

if (adminPost()) {
    try {
        // Colors
        foreach (['color_primary', 'color_dark', 'color_soft'] as $k) {
            $v = (string)($_POST[$k] ?? '');
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) saveSetting($k, strtolower($v));
        }
        if (isset($_POST['reset_colors'])) { saveSetting('color_primary', '#c0396b'); saveSetting('color_dark', '#151a21'); saveSetting('color_soft', '#f6efe8'); }
        // Logo
        if (isset($_POST['remove_logo'])) { deleteUpload(setting('logo')); saveSetting('logo', ''); }
        if ($path = uploadImage($_FILES['logo'] ?? [], 'categories')) { deleteUpload(setting('logo')); saveSetting('logo', $path); }
        if (isset($_POST['remove_logo_footer'])) { deleteUpload(setting('logo_footer')); saveSetting('logo_footer', ''); }
        if ($path = uploadImage($_FILES['logo_footer'] ?? [], 'categories')) { deleteUpload(setting('logo_footer')); saveSetting('logo_footer', $path); }
        // Simple texts
        foreach (['search_placeholder', 'newest_sub', 'featured_title', 'featured_sub', 'newest_title', 'sale_title', 'features_title', 'why_title', 'cats_title', 'cats_kicker', 'reviews_title', 'reviews_sub', 'footer_about', 'footer_tagline', 'newsletter_title', 'newsletter_text', 'promo_pill', 'promo_title', 'promo_text', 'promo_btn', 'promo_link'] as $k) {
            saveSetting($k, trim((string)($_POST[$k] ?? '')));
        }
        // French versions (empty = the Arabic text is shown in French too)
        foreach (APPEARANCE_FR as $k) saveSetting($k . '_fr', trim((string)($_POST[$k . '_fr'] ?? '')));
        saveSetting('brands_title', trim((string)($_POST['brands_title'] ?? '')));
        saveSetting('cta_title', trim((string)($_POST['cta_title'] ?? '')));
        saveSetting('cta_text', trim((string)($_POST['cta_text'] ?? '')));
        if (isset($_POST['remove_cta_image'])) { deleteUpload(setting('cta_image')); saveSetting('cta_image', ''); }
        if ($path = uploadImage($_FILES['cta_image'] ?? [], 'slides')) { deleteUpload(setting('cta_image')); saveSetting('cta_image', $path); }
        $brands = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($_POST['brands'] ?? ''))))));
        saveSetting('brands', json_encode(array_slice($brands, 0, 16), JSON_UNESCAPED_UNICODE));
        saveSetting('newsletter_on', isset($_POST['newsletter_on']) ? '1' : '0');
        saveSetting('show_see_all', isset($_POST['show_see_all']) ? '1' : '0');
        saveSetting('float_cta_on', isset($_POST['float_cta_on']) ? '1' : '0');
        saveSetting('wa_float_on', isset($_POST['wa_float_on']) ? '1' : '0');
        saveSetting('home_products_count', (string)max(1, min(48, (int)($_POST['home_products_count'] ?? 4))));
        // Top bar
        $topbar = [];
        for ($i = 0; $i < TOPBAR_SLOTS; $i++) {
            $t = trim((string)($_POST['tb_text'][$i] ?? ''));
            if ($t !== '') $topbar[] = ['icon' => array_key_exists($_POST['tb_icon'][$i] ?? '', iconChoices()) ? $_POST['tb_icon'][$i] : 'gift', 'text' => $t, 'text_fr' => trim((string)($_POST['tb_text_fr'][$i] ?? ''))];
        }
        saveSetting('topbar', json_encode($topbar, JSON_UNESCAPED_UNICODE));
        // Homepage sections (order + on/off)
        $secs = [];
        foreach (array_keys($labels) as $k) $secs[] = ['key' => $k, 'on' => isset($_POST['sec_on'][$k]) ? 1 : 0, 'order' => (int)($_POST['sec_order'][$k] ?? 99)];
        usort($secs, fn($a, $b) => $a['order'] <=> $b['order']);
        saveSetting('home_sections', json_encode(array_map(fn($s) => ['key' => $s['key'], 'on' => $s['on']], $secs)));
        // Banners
        $oldBanners = settingJson('banners');
        $banners = [];
        for ($i = 0; $i < BANNER_SLOTS; $i++) {
            $img = $oldBanners[$i]['image'] ?? '';
            if (isset($_POST['bn_remove_img'][$i])) { deleteUpload($img); $img = ''; }
            $file = ['name' => $_FILES['bn_img']['name'][$i] ?? '', 'tmp_name' => $_FILES['bn_img']['tmp_name'][$i] ?? '', 'error' => $_FILES['bn_img']['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $_FILES['bn_img']['size'][$i] ?? 0];
            if ($new = uploadImage($file, 'slides')) { deleteUpload($img); $img = $new; }
            $b = [
                'title' => trim((string)($_POST['bn_title'][$i] ?? '')), 'text' => trim((string)($_POST['bn_text'][$i] ?? '')),
                'btn' => trim((string)($_POST['bn_btn'][$i] ?? '')), 'link' => trim((string)($_POST['bn_link'][$i] ?? '')),
                'image' => $img, 'style' => in_array($_POST['bn_style'][$i] ?? '', ['pink', 'green', 'blue', 'dark', 'light'], true) ? $_POST['bn_style'][$i] : 'pink',
                'title_fr' => trim((string)($_POST['bn_title_fr'][$i] ?? '')), 'text_fr' => trim((string)($_POST['bn_text_fr'][$i] ?? '')), 'btn_fr' => trim((string)($_POST['bn_btn_fr'][$i] ?? '')),
            ];
            if ($b['title'] !== '' || $b['image'] !== '') $banners[] = $b;
            elseif ($img) deleteUpload($img);
        }
        saveSetting('banners', json_encode($banners, JSON_UNESCAPED_UNICODE));
        // Why-us features
        $features = [];
        for ($i = 0; $i < FEATURE_SLOTS; $i++) {
            $t = trim((string)($_POST['ft_title'][$i] ?? ''));
            if ($t !== '') $features[] = ['icon' => array_key_exists($_POST['ft_icon'][$i] ?? '', iconChoices()) ? $_POST['ft_icon'][$i] : 'star', 'title' => $t, 'text' => trim((string)($_POST['ft_text'][$i] ?? '')), 'title_fr' => trim((string)($_POST['ft_title_fr'][$i] ?? '')), 'text_fr' => trim((string)($_POST['ft_text_fr'][$i] ?? ''))];
        }
        saveSetting('features', json_encode($features, JSON_UNESCAPED_UNICODE));
        // "Why choose us" — the dark band
        $why = [];
        for ($i = 0; $i < WHY_SLOTS; $i++) {
            $t = trim((string)($_POST['why_title_i'][$i] ?? ''));
            if ($t !== '') $why[] = ['icon' => array_key_exists($_POST['why_icon'][$i] ?? '', iconChoices()) ? $_POST['why_icon'][$i] : 'star', 'title' => $t, 'text' => trim((string)($_POST['why_text'][$i] ?? '')), 'title_fr' => trim((string)($_POST['why_ttl_fr'][$i] ?? '')), 'text_fr' => trim((string)($_POST['why_text_fr'][$i] ?? ''))];
        }
        saveSetting('why', json_encode($why, JSON_UNESCAPED_UNICODE));
        // Hero badges
        $pills = [];
        for ($i = 0; $i < PILL_SLOTS; $i++) {
            $t = trim((string)($_POST['pill_text'][$i] ?? ''));
            if ($t !== '') $pills[] = ['icon' => array_key_exists($_POST['pill_icon'][$i] ?? '', iconChoices()) ? $_POST['pill_icon'][$i] : 'leaf', 'text' => $t,
                'text2' => trim((string)($_POST['pill_text2'][$i] ?? '')), 'text_fr' => trim((string)($_POST['pill_text_fr'][$i] ?? '')), 'text2_fr' => trim((string)($_POST['pill_text2_fr'][$i] ?? ''))];
        }
        saveSetting('hero_pills', json_encode($pills, JSON_UNESCAPED_UNICODE));
        // The dark promo banner
        saveSetting('promo_on', isset($_POST['promo_on']) ? '1' : '0');
        $promoImg = setting('promo_image');
        if (isset($_POST['remove_promo_image'])) { deleteUpload($promoImg); $promoImg = ''; }
        if ($new = uploadImage($_FILES['promo_image'] ?? [], 'slides')) { deleteUpload($promoImg); $promoImg = $new; }
        saveSetting('promo_image', $promoImg);
        $points = [];
        for ($i = 0; $i < PROMO_SLOTS; $i++) {
            $t = trim((string)($_POST['pr_text'][$i] ?? ''));
            if ($t !== '') $points[] = ['icon' => array_key_exists($_POST['pr_icon'][$i] ?? '', iconChoices()) ? $_POST['pr_icon'][$i] : 'badge', 'text' => $t, 'text_fr' => trim((string)($_POST['pr_text_fr'][$i] ?? ''))];
        }
        saveSetting('promo_points', json_encode($points, JSON_UNESCAPED_UNICODE));
        flash('تم حفظ الواجهة ✔ — افتح المتجر لرؤية التغييرات');
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    redirect('appearance.php');
}

$sections = settingJson('home_sections');
$known = array_column($sections, 'key');
foreach (array_keys($labels) as $k) if (!in_array($k, $known, true)) $sections[] = ['key' => $k, 'on' => 0];
$topbar = settingJson('topbar');
$banners = settingJson('banners');
$features = settingJson('features');
$why = settingJson('why');
$pills = settingJson('hero_pills');
$promoPoints = settingJson('promo_points');
$icons = iconChoices();
$iconSelect = function (string $name, string $current) use ($icons): string {
    $h = '<select name="' . $name . '" class="icon-select">';
    foreach ($icons as $k => $l) $h .= '<option value="' . $k . '"' . ($k === $current ? ' selected' : '') . '>' . $l . '</option>';
    return $h . '</select>';
};
$get = fn($k) => e(setting($k));
adminHeader('الواجهة والمظهر', 'appearance');
?>
<form method="post" enctype="multipart/form-data" class="form" id="appearanceForm">
<?= csrfField() ?>
<div class="sticky-save"><span class="hint" style="margin:0">كل ما في الصفحة الرئيسية قابل للتعديل من هنا. السلايدر في صفحة <a href="slides.php"><b>السلايدر</b></a>، والأيقونات في <a href="categories.php"><b>الأقسام</b></a>.</span><a href="../index.php" target="_blank" class="btn btn-light">عرض المتجر</a><button class="btn btn-gold">حفظ كل التغييرات</button></div>

<div class="grid-2">
  <div>
    <div class="panel form">
      <div class="panel-head"><h2>الألوان والشعار</h2></div>
      <div class="row three">
        <label>اللون المميز (التخفيضات والروابط)<input type="color" name="color_primary" value="<?= e(colorSetting('color_primary', '#c0396b')) ?>" class="color-in"></label>
        <label>اللون الداكن (الأزرار، الشريط العلوي وأسفل الموقع)<input type="color" name="color_dark" value="<?= e(colorSetting('color_dark', '#151a21')) ?>" class="color-in"></label>
        <label>لون الخلفيات الفاتحة (البيج)<input type="color" name="color_soft" value="<?= e(colorSetting('color_soft', '#f6efe8')) ?>" class="color-in"></label>
      </div>
      <label class="check"><input type="checkbox" name="reset_colors"> إرجاع الألوان الأصلية</label>
      <?php if ($logo = setting('logo')): ?><div class="img-setting"><img src="../<?= e($logo) ?>" alt="" style="object-fit:contain;background:#f5f5f5"><span>الشعار الحالي</span><label class="check red"><input type="checkbox" name="remove_logo"> حذف</label></div><?php endif; ?>
      <label>صورة الشعار (PNG بخلفية شفافة). إذا حذفتها يظهر اسم المتجر مكتوباً مع أيقونة زهرة اللوتس. استعمل شعاراً داكناً لأن رأس الصفحة أبيض.<input type="file" name="logo" accept="image/*"></label>
      <?php if ($lf = setting('logo_footer')): ?><div class="img-setting"><img src="../<?= e($lf) ?>" alt="" style="object-fit:contain;background:#111"><span>شعار أسفل الموقع (للخلفية الداكنة)</span><label class="check red"><input type="checkbox" name="remove_logo_footer"> حذف</label></div><?php endif; ?>
      <label>شعار أسفل الموقع (نسخة بألوان فاتحة تناسب الخلفية الداكنة — اختياري)<input type="file" name="logo_footer" accept="image/*"></label>
      <p class="hint">اسم المتجر والسطر الصغير تحته يُعدّلان من <a href="settings.php"><b>الإعدادات</b></a>.</p>
      <label>النص داخل خانة البحث<input type="text" name="search_placeholder" value="<?= $get('search_placeholder') ?>" placeholder="ابحث عن منتجك المفضل ..."></label>
      <label class="fr-in">FR<input type="text" name="search_placeholder_fr" value="<?= $get('search_placeholder_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
      <label class="check"><input type="checkbox" name="float_cta_on" <?= setting('float_cta_on') === '1' ? 'checked' : '' ?>> إظهار زر "تسوق الآن" العائم أسفل الشاشة (يأخذ نصه من أول شريحة)</label>
      <label class="check"><input type="checkbox" name="wa_float_on" <?= setting('wa_float_on', '1') === '1' ? 'checked' : '' ?>> إظهار زر واتساب العائم (يحتاج رقم واتساب في الإعدادات)</label>
    </div>

    <div class="panel form">
      <div class="panel-head"><h2>الشريط العلوي</h2></div>
      <?php for ($i = 0; $i < TOPBAR_SLOTS; $i++): $t = $topbar[$i] ?? ['icon' => 'gift', 'text' => '']; ?>
        <div class="slot-row"><?= $iconSelect("tb_icon[$i]", $t['icon']) ?><input type="text" name="tb_text[<?= $i ?>]" value="<?= e($t['text']) ?>" placeholder="نص (اتركه فارغاً للإخفاء)"><input type="text" name="tb_text_fr[<?= $i ?>]" value="<?= e($t['text_fr'] ?? '') ?>" dir="ltr" placeholder="Français"></div>
      <?php endfor; ?>
    </div>

    <div class="panel form">
      <div class="panel-head"><h2>ترتيب أقسام الصفحة الرئيسية</h2></div>
      <p class="hint">فعّل أو أوقف أي قسم، وغيّر ترتيبه بالأسهم.</p>
      <div id="sections">
        <?php foreach ($sections as $i => $s): if (!isset($labels[$s['key']])) continue; ?>
        <div class="sec-row">
          <input type="hidden" name="sec_order[<?= e($s['key']) ?>]" value="<?= $i ?>" class="sec-order">
          <label class="check"><input type="checkbox" name="sec_on[<?= e($s['key']) ?>]" <?= !empty($s['on']) ? 'checked' : '' ?>> <?= e($labels[$s['key']]) ?></label>
          <span class="sec-move"><button type="button" class="btn btn-light btn-sm" data-move="-1" aria-label="أعلى">▲</button><button type="button" class="btn btn-light btn-sm" data-move="1" aria-label="أسفل">▼</button></span>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="row three">
        <label>عنوان "أحدث المنتجات"<input type="text" name="newest_title" value="<?= $get('newest_title') ?>"></label>
      <label class="fr-in">FR<input type="text" name="newest_title_fr" value="<?= $get('newest_title_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
        <label>عنوان "المنتجات المميزة"<input type="text" name="featured_title" value="<?= $get('featured_title') ?>"></label>
      <label class="fr-in">FR<input type="text" name="featured_title_fr" value="<?= $get('featured_title_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
        <label>عنوان "العروض"<input type="text" name="sale_title" value="<?= $get('sale_title') ?>"></label>
      <label class="fr-in">FR<input type="text" name="sale_title_fr" value="<?= $get('sale_title_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
      </div>
      <div class="row">
        <label>سطر صغير تحت "أحدث المنتجات" (اختياري)<input type="text" name="newest_sub" value="<?= $get('newest_sub') ?>"></label>
      <label class="fr-in">FR<input type="text" name="newest_sub_fr" value="<?= $get('newest_sub_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
        <label>سطر صغير تحت "المنتجات المميزة" (اختياري)<input type="text" name="featured_sub" value="<?= $get('featured_sub') ?>"></label>
      <label class="fr-in">FR<input type="text" name="featured_sub_fr" value="<?= $get('featured_sub_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
      </div>
      <div class="row">
        <label>عدد المنتجات في كل قسم من الرئيسية (4 = صف واحد)<input type="number" name="home_products_count" min="1" max="48" value="<?= (int)setting('home_products_count', '4') ?>"></label>
        <label class="check" style="align-self:end"><input type="checkbox" name="show_see_all" <?= setting('show_see_all') === '1' ? 'checked' : '' ?>> إظهار رابط "عرض الكل" بجانب العنوان</label>
      </div>
      <p class="hint">"أحدث المنتجات" تعرض آخر المنتجات المضافة. "المنتجات المميزة" تعرض المنتجات التي فعّلت لها خيار "مميز". "العروض" تعرض المنتجات التي لها سعر قبل التخفيض.</p>
    </div>
  </div>

  <div>
    <div class="panel form">
      <div class="panel-head"><h2>مزايا السلايدر (تحت الزر)</h2></div>
      <p class="hint">تظهر تحت زر السلايدر: أيقونة، سطر بالخط العريض وسطر صغير تحته — بالعربية ثم بالفرنسية.</p>
      <?php for ($i = 0; $i < PILL_SLOTS; $i++): $pl = $pills[$i] ?? ['icon' => 'leaf', 'text' => '']; ?>
        <div class="slot-row"><?= $iconSelect("pill_icon[$i]", $pl['icon']) ?><input type="text" name="pill_text[<?= $i ?>]" value="<?= e($pl['text']) ?>" placeholder="مثلاً: توصيل سريع"><input type="text" name="pill_text2[<?= $i ?>]" value="<?= e($pl['text2'] ?? '') ?>" placeholder="سطر صغير: إلى 58 ولاية"></div>
        <div class="slot-row fr-row"><span class="fr-tag">FR</span><input type="text" name="pill_text_fr[<?= $i ?>]" value="<?= e($pl['text_fr'] ?? '') ?>" dir="ltr" placeholder="Livraison rapide"><input type="text" name="pill_text2_fr[<?= $i ?>]" value="<?= e($pl['text2_fr'] ?? '') ?>" dir="ltr" placeholder="dans les 58 wilayas"></div>
      <?php endfor; ?>
    </div>

    <div class="panel form">
      <div class="panel-head"><h2>قسم الأقسام</h2></div>
      <div class="row">
        <label>النص الصغير فوق العنوان (اختياري)<input type="text" name="cats_kicker" value="<?= $get('cats_kicker') ?>"></label>
      <label class="fr-in">FR<input type="text" name="cats_kicker_fr" value="<?= $get('cats_kicker_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
        <label>العنوان<input type="text" name="cats_title" value="<?= $get('cats_title') ?>" placeholder="اتركه فارغاً لإخفاء العنوان"></label>
      <label class="fr-in">FR<input type="text" name="cats_title_fr" value="<?= $get('cats_title_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
      </div>
      <p class="hint">البطاقات نفسها (الصورة والاسم والترتيب) من صفحة <strong>الأقسام</strong> — يظهر كل قسم مفعّل له خيار "الظهور في الرئيسية" (6 أقسام = صف واحد). القائمة المنسدلة "الأقسام" في الأعلى تعرض الأقسام المفعّل لها "إظهار في القائمة".</p>
    </div>

    <div class="panel form">
      <div class="panel-head"><h2>شريط واتساب أسفل الصفحة + الميزتان بجانبه</h2></div>
      <p class="hint">زر واتساب الأخضر يأخذ الرقم من <a href="settings.php"><b>الإعدادات</b></a> (واتساب، أو الهاتف إن لم يوجد). الميزتان بجانبه هما أول عنصرين في القائمة أدناه.</p>
      <div class="row">
        <label>عنوان زر واتساب<input type="text" name="cta_title" value="<?= $get('cta_title') ?>"></label>
        <label>السطر الصغير<input type="text" name="cta_text" value="<?= $get('cta_text') ?>"></label>
      </div>
      <div class="row">
        <label class="fr-in">FR<input type="text" name="cta_title_fr" value="<?= $get('cta_title_fr') ?>" dir="ltr"></label>
        <label class="fr-in">FR<input type="text" name="cta_text_fr" value="<?= $get('cta_text_fr') ?>" dir="ltr"></label>
      </div>
      <?php if ($ci = setting('cta_image')): ?><div class="img-setting"><img src="../<?= e($ci) ?>" alt=""><span>الصورة الحالية</span><label class="check red"><input type="checkbox" name="remove_cta_image"> حذف</label></div><?php endif; ?>
      <label>صورة صغيرة بجانب الميزتين (اختيارية)<input type="file" name="cta_image" accept="image/*"></label>
      <p class="hint" style="margin-top:14px"><b>الميزتان / قسم «لماذا تختارنا»:</b></p>
      <label>العنوان<input type="text" name="why_title" value="<?= $get('why_title') ?>"></label>
      <label class="fr-in">FR<input type="text" name="why_title_fr" value="<?= $get('why_title_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
      <?php for ($i = 0; $i < WHY_SLOTS; $i++): $w = $why[$i] ?? ['icon' => 'star', 'title' => '', 'text' => '']; ?>
        <div class="slot-row"><?= $iconSelect("why_icon[$i]", $w['icon']) ?><input type="text" name="why_title_i[<?= $i ?>]" value="<?= e($w['title']) ?>" placeholder="العنوان"><input type="text" name="why_text[<?= $i ?>]" value="<?= e($w['text']) ?>" placeholder="نص قصير"></div>
        <div class="slot-row fr-row"><span class="fr-tag">FR</span><input type="text" name="why_ttl_fr[<?= $i ?>]" value="<?= e($w['title_fr'] ?? '') ?>" dir="ltr" placeholder="Titre"><input type="text" name="why_text_fr[<?= $i ?>]" value="<?= e($w['text_fr'] ?? '') ?>" dir="ltr" placeholder="Texte"></div>
      <?php endfor; ?>
    </div>

    <div class="panel form">
      <div class="panel-head"><h2>شريط العبارة / العرض الخاص (خلفية داكنة)</h2></div>
      <label class="check"><input type="checkbox" name="promo_on" <?= setting('promo_on', '1') === '1' ? 'checked' : '' ?>> إظهار البانر</label>
      <?php if ($pi = setting('promo_image')): ?>
        <div class="img-setting"><img src="../<?= e($pi) ?>" alt=""><span>صورة البانر الحالية</span><label class="check red"><input type="checkbox" name="remove_promo_image"> حذف</label></div>
      <?php endif; ?>
      <label>صورة الخلفية (يُفضّل 2400×120 تقريباً — صورة عريضة داكنة)<input type="file" name="promo_image" accept="image/*"></label>
      <div class="row">
        <label>الشارة الصغيرة<input type="text" name="promo_pill" value="<?= $get('promo_pill') ?>" placeholder="عرض خاص"></label>
      <label class="fr-in">FR<input type="text" name="promo_pill_fr" value="<?= $get('promo_pill_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
        <label>نص الزر<input type="text" name="promo_btn" value="<?= $get('promo_btn') ?>" placeholder="تسوق الآن"></label>
      <label class="fr-in">FR<input type="text" name="promo_btn_fr" value="<?= $get('promo_btn_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
      </div>
      <label>العنوان<input type="text" name="promo_title" value="<?= $get('promo_title') ?>" placeholder="نظّم منزلك بأناقة"></label>
      <label class="fr-in">FR<input type="text" name="promo_title_fr" value="<?= $get('promo_title_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
      <label>النص<input type="text" name="promo_text" value="<?= $get('promo_text') ?>" placeholder="اختياري"></label>
      <label class="fr-in">FR<input type="text" name="promo_text_fr" value="<?= $get('promo_text_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
      <label>الرابط<select class="link-pick" data-target="promo_link_in"><option value="">— اختر قسماً أو اكتب الرابط أدناه —</option><option value="shop.php">كل المنتجات</option><option value="shop.php?sale=1">العروض</option><?php foreach (allCategories(false) as $c): ?><option value="shop.php?cat=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
        <input type="text" name="promo_link" id="promo_link_in" value="<?= $get('promo_link') ?>" dir="ltr" placeholder="shop.php?cat=1"></label>
      <p class="hint">نقاط اختيارية تظهر تحت العبارة:</p>
      <?php for ($i = 0; $i < PROMO_SLOTS; $i++): $pp = $promoPoints[$i] ?? ['icon' => 'badge', 'text' => '']; ?>
        <div class="slot-row"><?= $iconSelect("pr_icon[$i]", $pp['icon']) ?><input type="text" name="pr_text[<?= $i ?>]" value="<?= e($pp['text']) ?>" placeholder="مثلاً: منتجات أصلية"><input type="text" name="pr_text_fr[<?= $i ?>]" value="<?= e($pp['text_fr'] ?? '') ?>" dir="ltr" placeholder="Français"></div>
      <?php endfor; ?>
    </div>

    <div class="panel form">
      <div class="panel-head"><h2>آراء العملاء</h2></div>
      <label>العنوان<input type="text" name="reviews_title" value="<?= $get('reviews_title') ?>"></label>
      <label class="fr-in">FR<input type="text" name="reviews_title_fr" value="<?= $get('reviews_title_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
      <label>السطر الصغير تحته<input type="text" name="reviews_sub" value="<?= $get('reviews_sub') ?>" placeholder="تقييمكم هو دافعنا للاستمرار"></label>
      <label class="fr-in">FR<input type="text" name="reviews_sub_fr" value="<?= $get('reviews_sub_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
      <p class="hint">تظهر في الصفحة الرئيسية أحدث <strong>3 تقييمات موافق عليها</strong> من قسم <strong>التقييمات</strong>. لا تظهر تقييمات لم توافق عليها.</p>
    </div>

    <div class="panel form">
      <div class="panel-head"><h2>شريط المزايا (تحت السلايدر)</h2></div>
      <label>العنوان (اتركه فارغاً لإخفائه)<input type="text" name="features_title" value="<?= $get('features_title') ?>"></label>
      <label class="fr-in">FR<input type="text" name="features_title_fr" value="<?= $get('features_title_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
      <?php for ($i = 0; $i < FEATURE_SLOTS; $i++): $f = $features[$i] ?? ['icon' => 'star', 'title' => '', 'text' => '']; ?>
        <div class="slot-row"><?= $iconSelect("ft_icon[$i]", $f['icon']) ?><input type="text" name="ft_title[<?= $i ?>]" value="<?= e($f['title']) ?>" placeholder="العنوان"><input type="text" name="ft_text[<?= $i ?>]" value="<?= e($f['text']) ?>" placeholder="نص قصير"></div>
        <div class="slot-row fr-row"><span class="fr-tag">FR</span><input type="text" name="ft_title_fr[<?= $i ?>]" value="<?= e($f['title_fr'] ?? '') ?>" dir="ltr" placeholder="Titre"><input type="text" name="ft_text_fr[<?= $i ?>]" value="<?= e($f['text_fr'] ?? '') ?>" dir="ltr" placeholder="Texte"></div>
      <?php endfor; ?>
      <p class="hint">اترك العنوان فارغاً لإخفاء العنصر.</p>
    </div>

    <div class="panel form">
      <div class="panel-head"><h2>العلامات التجارية</h2></div>
      <p class="hint">علامة واحدة في كل سطر. تظهر في الشريط أسفل الصفحة الرئيسية وفي قائمة «العلامات» في الأعلى، والضغط عليها يعرض منتجاتها (حسب خانة «العلامة التجارية» في كل منتج).</p>
      <label>عنوان الشريط<input type="text" name="brands_title" value="<?= $get('brands_title') ?>"></label>
      <label class="fr-in">FR<input type="text" name="brands_title_fr" value="<?= $get('brands_title_fr') ?>" dir="ltr" placeholder="Nos marques partenaires"></label>
      <label>العلامات<textarea name="brands" rows="8" dir="ltr"><?= e(implode("\n", array_filter(settingJson('brands'), 'is_string'))) ?></textarea></label>
    </div>

    <div class="panel form">
      <div class="panel-head"><h2>أسفل الموقع</h2></div>
      <label>نبذة قصيرة<textarea name="footer_about" rows="2"><?= $get('footer_about') ?></textarea></label>
      <label class="fr-in">FR<textarea name="footer_about_fr" rows="2" dir="ltr"><?= $get('footer_about_fr') ?></textarea></label>
      <label>الشعار الصغير أسفل الصفحة<input type="text" name="footer_tagline" value="<?= $get('footer_tagline') ?>"></label>
      <label class="fr-in">FR<input type="text" name="footer_tagline_fr" value="<?= $get('footer_tagline_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
      <label class="check"><input type="checkbox" name="newsletter_on" <?= setting('newsletter_on') === '1' ? 'checked' : '' ?>> إظهار خانة الاشتراك في النشرة البريدية</label>
      <div class="row">
        <label>عنوان النشرة<input type="text" name="newsletter_title" value="<?= $get('newsletter_title') ?>"></label>
      <label class="fr-in">FR<input type="text" name="newsletter_title_fr" value="<?= $get('newsletter_title_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
        <label>نص النشرة<input type="text" name="newsletter_text" value="<?= $get('newsletter_text') ?>"></label>
      <label class="fr-in">FR<input type="text" name="newsletter_text_fr" value="<?= $get('newsletter_text_fr') ?>" dir="ltr" placeholder="Français — vide = texte arabe"></label>
      </div>
    </div>
  </div>
</div>

<div class="panel form">
  <div class="panel-head"><h2>البنرات الإعلانية</h2></div>
  <p class="hint">تظهر 3 بطاقات ملونة جنباً إلى جنب تحت "الأكثر مبيعاً". الصورة تظهر في جهة البطاقة والنص في الجهة الأخرى — يُفضّل صورة 600×500. اترك العنوان والصورة فارغين لإخفاء البطاقة.</p>
  <div class="banner-slots">
  <?php for ($i = 0; $i < BANNER_SLOTS; $i++): $b = ($banners[$i] ?? []) + ['title' => '', 'text' => '', 'btn' => '', 'link' => '', 'image' => '', 'style' => 'pink', 'title_fr' => '', 'text_fr' => '', 'btn_fr' => '']; ?>
    <div class="banner-slot">
      <strong>بنر <?= $i + 1 ?></strong>
      <?php if ($b['image']): ?><img src="../<?= e($b['image']) ?>" alt=""><label class="check red"><input type="checkbox" name="bn_remove_img[<?= $i ?>]"> حذف الصورة</label><?php endif; ?>
      <label>الصورة<input type="file" name="bn_img[<?= $i ?>]" accept="image/*"></label>
      <label>العنوان<input type="text" name="bn_title[<?= $i ?>]" value="<?= e($b['title']) ?>"></label>
      <label class="fr-in">FR<input type="text" name="bn_title_fr[<?= $i ?>]" value="<?= e($b['title_fr']) ?>" dir="ltr" placeholder="Titre"></label>
      <label>النص<input type="text" name="bn_text[<?= $i ?>]" value="<?= e($b['text']) ?>"></label>
      <label class="fr-in">FR<input type="text" name="bn_text_fr[<?= $i ?>]" value="<?= e($b['text_fr']) ?>" dir="ltr" placeholder="Texte"></label>
      <div class="row">
        <label>نص الزر<input type="text" name="bn_btn[<?= $i ?>]" value="<?= e($b['btn']) ?>"></label>
        <label>نص الزر FR<input type="text" name="bn_btn_fr[<?= $i ?>]" value="<?= e($b['btn_fr']) ?>" dir="ltr" placeholder="Découvrir"></label>
      </div>
      <div class="row">
        <label>اللون<select name="bn_style[<?= $i ?>]"><?php foreach (['pink' => 'وردي', 'green' => 'أخضر', 'blue' => 'أزرق', 'light' => 'بيج فاتح', 'dark' => 'داكن'] as $sk => $sl): ?><option value="<?= $sk ?>" <?= $b['style'] === $sk ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach; ?></select></label>
      </div>
      <label>الرابط<select class="link-pick" data-target="bn_link_<?= $i ?>"><option value="">— اختر قسماً أو اكتب الرابط أدناه —</option><option value="shop.php">كل المنتجات</option><option value="shop.php?sale=1">العروض</option><?php foreach (allCategories(false) as $c): ?><option value="shop.php?cat=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
        <input type="text" name="bn_link[<?= $i ?>]" id="bn_link_<?= $i ?>" value="<?= e($b['link']) ?>" dir="ltr" placeholder="shop.php?cat=2"></label>
    </div>
  <?php endfor; ?>
  </div>
</div>
<button class="btn btn-gold btn-block">حفظ كل التغييرات</button>
</form>
<script>
(function () {
  var box = document.getElementById('sections');
  function renumber() { box.querySelectorAll('.sec-row').forEach(function (r, i) { r.querySelector('.sec-order').value = i; }); }
  box.addEventListener('click', function (e) {
    var b = e.target.closest('[data-move]'); if (!b) return;
    var row = b.closest('.sec-row');
    if (b.dataset.move === '-1' && row.previousElementSibling) box.insertBefore(row, row.previousElementSibling);
    if (b.dataset.move === '1' && row.nextElementSibling) box.insertBefore(row.nextElementSibling, row);
    renumber();
  });
  document.querySelectorAll('.link-pick').forEach(function (s) {
    s.addEventListener('change', function () { if (s.value) document.getElementById(s.dataset.target).value = s.value; });
  });
})();
</script>
<?php adminFooter(); ?>
