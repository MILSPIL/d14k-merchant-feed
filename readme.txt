=== GMC Feed for WooCommerce ===
Contributors: MIL SPIL
Tags: woocommerce, google merchant center, product feed, xml feed, wpml
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 1.0.19
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Simple and robust Google Merchant Center XML feed generator for WooCommerce. Supports WPML, variable products, and automatic updates.

== Description ==

**English**

GMC Feed for WooCommerce is a lightweight and powerful plugin designed to generate XML product feeds for Google Merchant Center. It is built with simplicity and performance in mind, ensuring your products are correctly listed on Google Shopping.

**Key Features:**
*   **WooCommerce Support:** Works seamlessly with Simple and Variable products.
*   **WPML Compatibility:** Automatically generates separate feeds for each language (UK, EN, RU, etc.) with correct currency conversion (requires WCML).
*   **Google Merchant Center Compliant:** Includes all essential tags (`g:id`, `g:title`, `g:price`, `g:availability`, `g:gtin`, `g:mpn`, `g:brand`, `g:google_product_category`, `g:item_group_id`).
*   **Automatic Updates:** Set cron schedules to update your feed every 3, 6, 12 hours, daily, weekly, or monthly.
*   **Built-in Validator:** Includes a "Test Validation" tool to check your products against GMC requirements before submission.
*   **Smart Defaults:** Automatically maps WooCommerce attributes to Google fields.
*   **Country of Origin:** Easy selection of the manufacturing country.

**Українська**

D14K Merchant Feed — це легкий та потужний плагін для генерації XML-фідів товарів для Google Merchant Center. Він створений для простоти та продуктивності, гарантуючи правильне відображення ваших товарів у Google Shopping.

**Основні можливості:**
*   **Підтримка WooCommerce:** Працює як з простими (Simple), так і з варіативними (Variable) товарами.
*   **Сумісність з WPML:** Автоматично генерує окремі фіди для кожної мови (UK, EN, RU тощо) з правильною конвертацією валют (потрібен WCML).
*   **Відповідність Google Merchant Center:** Включає всі необхідні теги (`g:id`, `g:title`, `g:price`, `g:availability`, `g:gtin`, `g:mpn`, `g:brand`, `g:google_product_category`, `g:item_group_id`).
*   **Автоматичне оновлення:** Налаштуйте розклад оновлення фіду: кожні 3, 6, 12 годин, щодня, щотижня або щомісяця.
*   **Вбудований валідатор:** Функція "Тестова перевірка" дозволяє перевірити товари на відповідність вимогам GMC перед відправкою.
*   **Розумні налаштування:** Автоматичний маппінг атрибутів WooCommerce до полів Google.
*   **Країна походження:** Легкий вибір країни виробництва товарів.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/d14k-merchant-feed` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Use the **WooCommerce > Merchant Feed** screen to configure the plugin settings.
4. Select your "Country of Origin" and "Default Google Product Category".
5. Click "Generate Now" or wait for the scheduled cron job.
6. Copy the Feed URL and submit it to Google Merchant Center.

== Frequently Asked Questions ==

= Does it support variable products? =
Yes, it fully supports WooCommerce variable products. Each variation is exported as a separate item with a shared `item_group_id`.

= Does it work with WPML? =
Yes! If WPML is active, the plugin creates separate feed URLs for each language.

= Can I use it for simple products only? =
Yes, version 1.0.16 added full support for simple products.

== Screenshots ==

1. **General Settings:** Configure brand, country, and update interval.
2. **Feed List:** View generated feed URLs for each language.
3. **Validation Tool:** Check your products for missing fields.

== Changelog ==

= 1.0.17 =
*   Added domain restriction for .ru zone.
*   Added readme.txt for repository compliance.

= 1.0.16 =
*   Added support for Simple Products.

= 1.0.15 =
*   Added XML preview in validation tool.

= 1.0.14 =
*   Fixed language switching bug in admin panel.

= 1.0.10 =
*   Improved validation logic for country_of_origin.

= 1.0.0 =
*   Initial release.
