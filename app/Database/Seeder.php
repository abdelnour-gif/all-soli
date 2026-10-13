<?php
namespace QueuePro\Database;

defined('QP_APP') || exit;

/** Idempotent reference data + default settings. Safe to run on every activation. */
final class Seeder
{
    public static function run(Connection $db): void
    {
        $now = gmdate('Y-m-d H:i:s');

        foreach ([
            ['DZD', 'دينار جزائري', 'Algerian Dinar', 'دج', 2],
            ['MAD', 'درهم مغربي', 'Moroccan Dirham', 'د.م', 2],
            ['TND', 'دينار تونسي', 'Tunisian Dinar', 'د.ت', 3],
            ['SAR', 'ريال سعودي', 'Saudi Riyal', 'ر.س', 2],
            ['EUR', 'يورو', 'Euro', '€', 2],
            ['USD', 'دولار أمريكي', 'US Dollar', '$', 2],
        ] as [$code, $ar, $en, $sym, $dec]) {
            $db->query(
                $db->insertIgnore() . " INTO {$db->table('currencies')} (code,name_ar,name_en,symbol,decimals) VALUES (%s,%s,%s,%s,%d)",
                [$code, $ar, $en, $sym, $dec]
            );
        }

        foreach ([['ar', 'العربية', 'rtl', 1], ['en', 'English', 'ltr', 1], ['fr', 'Français', 'ltr', 0]] as [$c, $n, $d, $a]) {
            $db->query($db->insertIgnore() . " INTO {$db->table('languages')} (code,name,direction,is_active) VALUES (%s,%s,%s,%d)", [$c, $n, $d, $a]);
        }

        // Only Algeria active at launch; others ready.
        foreach ([
            ['DZ', 'الجزائر', 'Algeria', '+213', 'DZD', 'Africa/Algiers', 1],
            ['MA', 'المغرب', 'Morocco', '+212', 'MAD', 'Africa/Casablanca', 0],
            ['TN', 'تونس', 'Tunisia', '+216', 'TND', 'Africa/Tunis', 0],
            ['SA', 'السعودية', 'Saudi Arabia', '+966', 'SAR', 'Asia/Riyadh', 0],
        ] as [$code, $ar, $en, $pc, $cur, $tz, $active]) {
            $db->query(
                $db->insertIgnore() . " INTO {$db->table('countries')} (code,name_ar,name_en,phone_code,currency_code,default_timezone,is_active) VALUES (%s,%s,%s,%s,%s,%s,%d)",
                [$code, $ar, $en, $pc, $cur, $tz, $active]
            );
        }

        if ((int) $db->getVar("SELECT COUNT(*) FROM {$db->table('cities')}") === 0) {
            foreach ([
                ['الجزائر العاصمة', 'Algiers'], ['البليدة', 'Blida'], ['وهران', 'Oran'], ['قسنطينة', 'Constantine'],
                ['عنابة', 'Annaba'], ['سطيف', 'Setif'], ['تيزي وزو', 'Tizi Ouzou'], ['باتنة', 'Batna'],
                ['بجاية', 'Bejaia'], ['تلمسان', 'Tlemcen'], ['المدية', 'Medea'], ['بومرداس', 'Boumerdes'],
            ] as [$ar, $en]) {
                $db->insert($db->table('cities'), ['country_code' => 'DZ', 'name_ar' => $ar, 'name_en' => $en]);
            }
        }

        // Categories mirror the reference design.
        foreach ([
            ['barbers', 'الحلاقة والتجميل', 'Barbers & Beauty', 'scissors', 1],
            ['health', 'الطب والصحة', 'Health & Clinics', 'stethoscope', 2],
            ['restaurants', 'المطاعم والمقاهي', 'Restaurants & Cafes', 'utensils', 3],
            ['pharmacies', 'الصيدليات', 'Pharmacies', 'pill', 4],
            ['services', 'الخدمات', 'Services', 'grid', 5],
            ['government', 'الإدارات', 'Public offices', 'building', 6],
        ] as [$slug, $ar, $en, $icon, $order]) {
            $db->query(
                $db->insertIgnore() . " INTO {$db->table('categories')} (slug,name_ar,name_en,icon,sort_order) VALUES (%s,%s,%s,%s,%d)",
                [$slug, $ar, $en, $icon, $order]
            );
        }

        foreach (\QueuePro\Services\Settings::DEFAULTS as $key => $value) {
            $db->query(
                $db->insertIgnore() . " INTO {$db->table('settings')} (setting_key,setting_value,updated_at) VALUES (%s,%s,%s)",
                [$key, (string) $value, $now]
            );
        }

        if ((int) $db->getVar("SELECT COUNT(*) FROM {$db->table('packages')}") === 0) {
            foreach ([['باقة 50', 'Pack 50', 50, 1000, 1], ['باقة 150', 'Pack 150', 150, 2500, 2], ['باقة 500', 'Pack 500', 500, 7000, 3]] as [$ar, $en, $c, $p, $o]) {
                $db->insert($db->table('packages'), [
                    'name_ar' => $ar, 'name_en' => $en, 'credits' => $c, 'price' => $p,
                    'currency_code' => 'DZD', 'sort_order' => $o, 'created_at' => $now,
                ]);
            }
        }
    }
}
