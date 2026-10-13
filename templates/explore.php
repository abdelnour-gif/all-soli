<?php
/** Explore providers: domain.com/explore/?q=&category=&city_id=&page= (works without JavaScript). */
defined('QP_APP') || exit;
require_once __DIR__ . '/partials/layout.php';
require_once __DIR__ . '/partials/components.php';

$c = qp();
$lang = qp_lang();
$L = fn(string $ar, string $en) => $lang === 'en' ? $en : $ar;
$db = $c->db;
$q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
$cat = isset($_GET['category']) ? sanitize_key($_GET['category']) : '';
$city = isset($_GET['city_id']) ? (int) $_GET['city_id'] : 0;
$page = max(1, (int) ($_GET['pg'] ?? 1));
$per = 12;

$cats = $db->getResults("SELECT slug, name_ar, name_en, icon FROM {$db->table('categories')} WHERE is_active = 1 ORDER BY sort_order");
$cities = $db->getResults("SELECT id, name_ar, name_en FROM {$db->table('cities')} WHERE is_active = 1 ORDER BY id");
$rows = $c->providers->search(['q' => $q, 'category' => $cat, 'city_id' => $city], $page, $per + 1);
$hasMore = count($rows) > $per;
$rows = array_slice($rows, 0, $per);
$base = array_filter(['q' => $q, 'category' => $cat, 'city_id' => $city ?: null]);

qp_header(qp_t('providers') . ' — ' . qp_brand(), ['wide' => true, 'nav' => 'providers']);
?>
<section class="qp-page-head">
  <div class="qp-container">
    <h1><?php echo esc_html(qp_t('providers')); ?></h1>
    <p><?php echo esc_html($L('اختر المزوّد وخذ رقمك من هاتفك', 'Choose a provider and take your number')); ?></p>
  </div>
</section>
<div class="qp-container">
  <form class="qp-filters" method="get" action="<?php echo esc_url(url('/explore/')); ?>" role="search">
    <div class="qp-input-ico"><?php echo qp_icon('search', 18); ?><input class="qp-input" name="q" value="<?php echo esc_attr($q); ?>" placeholder="<?php echo esc_attr(qp_t('search_ph')); ?>" aria-label="<?php echo esc_attr(qp_t('search')); ?>"></div>
    <select class="qp-input" name="category" aria-label="<?php echo esc_attr(qp_t('all_categories')); ?>">
      <option value=""><?php echo esc_html(qp_t('all_categories')); ?></option>
      <?php foreach ($cats as $ca): ?><option value="<?php echo esc_attr($ca['slug']); ?>" <?php selected($cat, $ca['slug']); ?>><?php echo esc_html(qp_name($ca)); ?></option><?php endforeach; ?>
    </select>
    <select class="qp-input" name="city_id" aria-label="<?php echo esc_attr(qp_t('all_cities')); ?>">
      <option value=""><?php echo esc_html(qp_t('all_cities')); ?></option>
      <?php foreach ($cities as $ci): ?><option value="<?php echo (int) $ci['id']; ?>" <?php selected($city, (int) $ci['id']); ?>><?php echo esc_html(qp_name($ci)); ?></option><?php endforeach; ?>
    </select>
    <button class="qp-btn" type="submit"><?php echo esc_html(qp_t('search')); ?></button>
  </form>

  <nav class="qp-chips" aria-label="<?php echo esc_attr(qp_t('all_categories')); ?>">
    <a href="<?php echo esc_url(add_query_arg(array_diff_key($base, ['category' => 1]), url('/explore/'))); ?>" class="<?php echo $cat === '' ? 'is-active' : ''; ?>"><?php echo qp_icon('grid', 16) . esc_html(qp_t('all_categories')); ?></a>
    <?php foreach ($cats as $ca): ?>
      <a href="<?php echo esc_url(add_query_arg(['category' => $ca['slug']] + $base, url('/explore/'))); ?>" class="<?php echo $cat === $ca['slug'] ? 'is-active' : ''; ?>"><?php echo qp_icon($ca['icon'], 16) . esc_html(qp_name($ca)); ?></a>
    <?php endforeach; ?>
  </nav>

  <section class="qp-sec" style="padding-top:16px">
    <?php if ($rows): ?>
      <div class="qp-cards"><?php foreach ($rows as $p) echo qp_provider_card($p); ?></div>
      <div class="qp-pager">
        <?php if ($page > 1): ?><a href="<?php echo esc_url(add_query_arg(['pg' => $page - 1] + $base, url('/explore/'))); ?>"><?php echo esc_html($L('السابق', 'Previous')); ?></a><?php endif; ?>
        <?php if ($hasMore): ?><a href="<?php echo esc_url(add_query_arg(['pg' => $page + 1] + $base, url('/explore/'))); ?>"><?php echo esc_html($L('المزيد', 'More')); ?></a><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="qp-empty"><?php echo qp_icon('search', 32); ?><p><?php echo esc_html($L('لا يوجد مزوّدون مطابقون لبحثك.', 'No providers match your search.')); ?></p>
        <a href="<?php echo esc_url(url('/explore/')); ?>"><?php echo esc_html($L('عرض كل المزوّدين', 'Show all providers')); ?></a></div>
    <?php endif; ?>
  </section>
</div>
<?php qp_footer(['footer' => true]);
